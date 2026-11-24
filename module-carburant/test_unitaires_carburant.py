"""Tests unitaires du module Carburant (identifiants UT-CAR-xx).

Chaque test appelle directement une fonction du module (validation, regle de
gestion, calcul) avec une base SQLite en memoire: pas de serveur HTTP, pas de
dependance a MySQL ni aux autres services (Vehicule et Chauffeur sont simules
par la fixture "services" de conftest.py).

Lancer:  pytest test_unitaires_carburant.py -v
Rapport: pytest test_unitaires_carburant.py --junitxml=rapport_tests_unitaires.xml

Les tests marques skip correspondent a des regles de gestion pas encore
developpees (RG-02, RG-03, RG-06, RG-07). BUG-01 et BUG-02 sont corriges:
UT-CAR-11 et UT-CAR-12 passent maintenant.
"""
from datetime import date

import httpx
import pytest
from fastapi import HTTPException
from pydantic import ValidationError
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker
from sqlalchemy.pool import StaticPool

import clients
import models
import schemas
from anomalies import recalculer_vehicule
from database import Base
from routers import carburant as svc


# ---------------------------------------------------------------- outils

@pytest.fixture()
def db(services):
    """Base en memoire, vide, recreee pour chaque test (Arrange)."""
    engine = create_engine(
        "sqlite://", connect_args={"check_same_thread": False}, poolclass=StaticPool
    )
    Base.metadata.create_all(bind=engine)
    session = sessionmaker(bind=engine, autocommit=False, autoflush=False)()
    session.services = services  # acces aux faux services Vehicule / Chauffeur
    yield session
    session.close()
    engine.dispose()


def nouveau_vehicule(db, immat=None):
    """Declare un vehicule connu du (faux) service Vehicule. 'immat' est ignore."""
    return db.services.nouveau_vehicule()


def nouveau_chauffeur(db):
    """Declare un chauffeur connu du (faux) service Chauffeur."""
    return db.services.nouveau_chauffeur()


def donnees(vid, jour, litres, km, prix=11.5, chauffeur_id=None, **extra):
    return schemas.CarburantCreate(
        vehicule_id=vid,
        chauffeur_id=chauffeur_id,
        date_plein=date.fromisoformat(jour),
        quantite_litres=litres,
        prix_litre=prix,
        kilometrage=km,
        type_carburant="diesel",
        **extra,
    )


def ajouter(db, vid, jour, litres, km, **kw):
    return svc.creer_plein(donnees(vid, jour, litres, km, **kw), db)


def historique_normal(db, vid):
    """Vehicule a 7 L/100 km: 40 L puis 42 L aux 600 km, deux fois."""
    ajouter(db, vid, "2026-10-01", 40, 10000)
    ajouter(db, vid, "2026-10-08", 42, 10600)
    ajouter(db, vid, "2026-10-15", 42, 11200)


# ------------------------------------------- validation des donnees (schemas)

def test_ut_car_01_plein_valide_accepte():
    plein = donnees(1, "2026-10-17", 40, 10000)
    assert plein.quantite_litres == 40
    assert plein.type_carburant == schemas.TypeCarburant.diesel
    assert plein.plein_complet is True


def test_ut_car_02_quantite_nulle_ou_negative_refusee():
    for litres in (0, -5):
        with pytest.raises(ValidationError):
            donnees(1, "2026-10-17", litres, 10000)


def test_ut_car_03_prix_nul_ou_negatif_refuse():
    for prix in (0, -1):
        with pytest.raises(ValidationError):
            donnees(1, "2026-10-17", 40, 10000, prix=prix)


def test_ut_car_04_kilometrage_negatif_refuse():
    with pytest.raises(ValidationError):
        donnees(1, "2026-10-17", 40, -1)


def test_ut_car_05_type_carburant_hors_liste_refuse():
    with pytest.raises(ValidationError):
        schemas.CarburantCreate(
            vehicule_id=1, date_plein=date(2026, 10, 17), quantite_litres=40,
            prix_litre=11.5, kilometrage=10000, type_carburant="kerosene",
        )


# ------------------------------------------------------ references (RG-01 liens)

def test_ut_car_06_vehicule_inexistant_refuse(db):
    with pytest.raises(HTTPException) as e:
        svc.verifier_references(999, None)
    assert e.value.status_code == 404


def test_ut_car_07_chauffeur_inexistant_refuse(db):
    vid = nouveau_vehicule(db)
    with pytest.raises(HTTPException) as e:
        svc.verifier_references(vid, 999)
    assert e.value.status_code == 404


def test_ut_car_08_chauffeur_facultatif(db):
    vid = nouveau_vehicule(db)
    plein = ajouter(db, vid, "2026-10-17", 40, 10000)  # sans chauffeur
    assert plein.chauffeur_id is None


def test_dernier_entretien_ignore_les_interventions_non_terminees(monkeypatch):
    entretiens = [
        {"id": 3, "date_entretien": "2026-10-20", "type": "PREVENTIF", "statut": "PLANIFIE"},
        {"id": 1, "date_entretien": "2026-10-01", "type": "CORRECTIF", "statut": "TERMINE"},
        {"id": 2, "date_entretien": "2026-10-15", "type": "PREVENTIF", "categorie": "VIDANGE", "statut": "TERMINE"},
    ]
    monkeypatch.setattr(clients, "lister_entretiens_vehicule", lambda vid: entretiens)
    assert clients.dernier_entretien_vehicule(1) == {
        "date_entretien": "2026-10-15",
        "type": "PREVENTIF",
        "categorie": "VIDANGE",
    }


def test_dernier_entretien_absent_si_aucune_intervention_terminee(monkeypatch):
    monkeypatch.setattr(
        clients,
        "lister_entretiens_vehicule",
        lambda vid: [{"date_entretien": "2026-10-20", "type": "PREVENTIF", "statut": "PLANIFIE"}],
    )
    assert clients.dernier_entretien_vehicule(1) is None


# ------------------------------------------------ RG-01 kilometrage croissant

def test_ut_car_09_kilometrage_superieur_accepte(db):
    vid = nouveau_vehicule(db)
    ajouter(db, vid, "2026-10-01", 40, 10000)
    # ne doit pas lever d'exception
    svc.verifier_kilometrage(db, vid, date(2026, 10, 8), 10600)


def test_ut_car_10_kilometrage_inferieur_refuse(db):
    vid = nouveau_vehicule(db)
    ajouter(db, vid, "2026-10-01", 40, 10000)
    with pytest.raises(HTTPException) as e:
        svc.verifier_kilometrage(db, vid, date(2026, 10, 8), 9500)
    assert e.value.status_code == 400
    assert "incoherent" in e.value.detail.lower()


def test_ut_car_11_kilometrage_egal_refuse(db):
    vid = nouveau_vehicule(db)
    ajouter(db, vid, "2026-10-01", 40, 10000)
    with pytest.raises(HTTPException) as e:
        svc.verifier_kilometrage(db, vid, date(2026, 10, 8), 10000)
    assert e.value.status_code == 400


def test_ut_car_12_erreur_400_attendue(db):
    vid = nouveau_vehicule(db)
    ajouter(db, vid, "2026-10-01", 40, 10000)
    with pytest.raises(HTTPException) as e:
        svc.verifier_kilometrage(db, vid, date(2026, 10, 8), 9500)
    assert e.value.status_code == 400


def test_ut_car_13_insertion_entre_deux_pleins_incoherente_refusee(db):
    vid = nouveau_vehicule(db)
    ajouter(db, vid, "2026-10-01", 40, 10000)
    ajouter(db, vid, "2026-10-20", 40, 10800)
    # plein date au milieu avec un compteur superieur au plein suivant
    with pytest.raises(HTTPException) as e:
        svc.verifier_kilometrage(db, vid, date(2026, 10, 10), 11000)
    assert e.value.status_code == 400
    # valeur coherente entre les deux: acceptee
    svc.verifier_kilometrage(db, vid, date(2026, 10, 10), 10400)


# ------------------------------------------------------- RG-05 calcul du montant

def test_ut_car_14_montant_egal_litres_fois_prix(db):
    vid = nouveau_vehicule(db)
    plein = ajouter(db, vid, "2026-10-17", 40, 10000, prix=11.5)
    assert plein.montant_total == 460.0


def test_ut_car_15_montant_arrondi_a_deux_decimales(db):
    vid = nouveau_vehicule(db)
    plein = ajouter(db, vid, "2026-10-17", 33.33, 10000, prix=10.97)
    assert plein.montant_total == 365.63  # 33.33 x 10.97 = 365.6301, arrondi


def test_ut_car_16_montant_recalcule_apres_modification(db):
    vid = nouveau_vehicule(db)
    plein = ajouter(db, vid, "2026-10-17", 40, 10000, prix=11.5)
    modifie = svc.modifier_plein(
        plein.id, schemas.CarburantUpdate(quantite_litres=50), db
    )
    assert modifie.montant_total == 575.0


# --------------------------------------------------- RG-04 calcul consommation

def test_ut_car_17_premier_plein_sans_consommation(db):
    vid = nouveau_vehicule(db)
    plein = ajouter(db, vid, "2026-10-01", 40, 10000)
    assert plein.consommation_l_100km is None


def test_ut_car_18_consommation_litres_sur_distance(db):
    vid = nouveau_vehicule(db)
    ajouter(db, vid, "2026-10-01", 40, 10000)
    second = ajouter(db, vid, "2026-10-08", 42, 10600)
    assert second.consommation_l_100km == 7.0  # 42 L / 600 km x 100


def test_ut_car_19_plein_partiel_sans_consommation(db):
    vid = nouveau_vehicule(db)
    ajouter(db, vid, "2026-10-01", 40, 10000)
    partiel = ajouter(db, vid, "2026-10-08", 20, 10600, plein_complet=False)
    assert partiel.consommation_l_100km is None


def test_ut_car_20_distance_nulle_sans_division_par_zero(db):
    vid = nouveau_vehicule(db)
    for jour in ("2026-10-01", "2026-10-08"):  # meme kilometrage: insertion directe
        db.add(models.Carburant(
            vehicule_id=vid, date_plein=date.fromisoformat(jour), quantite_litres=40,
            prix_litre=11.5, montant_total=460.0, kilometrage=10000,
            type_carburant="diesel", plein_complet=True,
        ))
    db.commit()
    recalculer_vehicule(db, vid)  # ne doit pas lever ZeroDivisionError
    assert all(p.consommation_l_100km is None for p in db.query(models.Carburant))


def test_ut_car_20b_plein_partiel_inclus_dans_conso_plein_complet_suivant(db):
    vid = nouveau_vehicule(db)
    complet_1 = ajouter(db, vid, "2026-10-01", 40, 1000)
    partiel = ajouter(db, vid, "2026-10-10", 20, 1300, plein_complet=False)
    complet_2 = ajouter(db, vid, "2026-10-20", 20, 1600)
    assert complet_1.consommation_l_100km is None
    assert partiel.consommation_l_100km is None
    assert complet_2.consommation_l_100km == 6.67


# ------------------------------------------------ alerte de surconsommation

def test_ut_car_21_consommation_double_signalee(db):
    vid = nouveau_vehicule(db)
    historique_normal(db, vid)
    anormal = ajouter(db, vid, "2026-10-22", 84, 11800)  # 14 L/100 km
    assert anormal.consommation_l_100km == 14.0
    assert anormal.anomalie is True
    assert "Surconsommation" in anormal.message_anomalie


def test_ut_car_22_petite_variation_non_signalee(db):
    vid = nouveau_vehicule(db)
    historique_normal(db, vid)
    plein = ajouter(db, vid, "2026-10-22", 46.2, 11800)  # 7.7 L/100 km = +10 %
    assert plein.consommation_l_100km == 7.7
    assert plein.anomalie is False


def test_ut_car_23_historique_insuffisant_non_signale(db):
    vid = nouveau_vehicule(db)
    ajouter(db, vid, "2026-10-01", 40, 10000)
    ajouter(db, vid, "2026-10-08", 42, 10600)         # 7.0 (1re consommation)
    double = ajouter(db, vid, "2026-10-15", 84, 11200)  # 14.0 mais 1 seule reference
    assert double.consommation_l_100km == 14.0
    assert double.anomalie is False


def test_ut_car_24_seuil_exact_de_30_pour_cent(db):
    # Vehicule A: moyenne 7.0, seuil = 7.0 x 1.3 = 9.10 -> 9.10 pile n'est pas une anomalie
    a = nouveau_vehicule(db, "11111-A-1")
    historique_normal(db, a)
    sur_le_seuil = ajouter(db, a, "2026-10-22", 54.6, 11800)      # 9.10 L/100 km
    assert sur_le_seuil.consommation_l_100km == 9.1
    assert sur_le_seuil.anomalie is False                          # limite non depassee
    # Vehicule B (meme historique): 9.15 depasse le seuil de 9.10 -> anomalie
    b = nouveau_vehicule(db, "22222-B-2")
    historique_normal(db, b)
    au_dessus = ajouter(db, b, "2026-10-22", 54.9, 11800)         # 9.15 L/100 km
    assert au_dessus.consommation_l_100km == 9.15
    assert au_dessus.anomalie is True


def test_ut_car_25_suppression_du_plein_anormal_retire_l_alerte(db):
    vid = nouveau_vehicule(db)
    historique_normal(db, vid)
    anormal = ajouter(db, vid, "2026-10-22", 84, 11800)
    assert len(svc.lister_anomalies(vehicule_id=None, date_debut=None, date_fin=None, db=db)) == 1
    svc.supprimer_plein(anormal.id, db)
    assert svc.lister_anomalies(vehicule_id=None, date_debut=None, date_fin=None, db=db) == []


def test_ut_car_26_vehicules_independants(db):
    a = nouveau_vehicule(db, "11111-A-1")
    b = nouveau_vehicule(db, "22222-B-2")
    historique_normal(db, a)                       # A: 7 L/100 km
    ajouter(db, b, "2026-10-01", 40, 10000)        # B: toujours 14 L/100 km
    ajouter(db, b, "2026-10-08", 84, 10600)
    ajouter(db, b, "2026-10-15", 84, 11200)
    dernier_b = ajouter(db, b, "2026-10-22", 84, 11800)
    # la moyenne de B vient de B seulement: 14 reste normal pour lui
    assert dernier_b.anomalie is False
    assert svc.lister_anomalies(vehicule_id=None, date_debut=None, date_fin=None, db=db) == []


# --------------------------------------------------- statistiques et lecture

def test_ut_car_27_statistiques_cout_et_consommation(db):
    vid = nouveau_vehicule(db)
    ajouter(db, vid, "2026-10-01", 40, 10000)
    ajouter(db, vid, "2026-10-10", 36, 10600)
    ajouter(db, vid, "2026-10-20", 30, 11100)
    s = svc.stats_vehicule(vid, date_debut=None, date_fin=None, db=db)
    assert s.nombre_pleins == 3
    assert s.total_litres == 106
    assert s.cout_total == 1219.0
    assert s.distance_km == 1100
    assert s.consommation_l_100km == 6.0  # (36 + 30) L / 1100 km x 100


def test_ut_car_28_statistiques_vehicule_inexistant(db):
    with pytest.raises(HTTPException) as e:
        svc.stats_vehicule(999, date_debut=None, date_fin=None, db=db)
    assert e.value.status_code == 404


def test_ut_car_29_lecture_plein_inexistant(db):
    with pytest.raises(HTTPException) as e:
        svc.lire_plein(999, db)
    assert e.value.status_code == 404


def test_ut_car_30_liste_filtree_par_vehicule_et_date(db):
    a = nouveau_vehicule(db, "11111-A-1")
    b = nouveau_vehicule(db, "22222-B-2")
    ajouter(db, a, "2026-09-01", 40, 10000)
    ajouter(db, a, "2026-09-15", 40, 10500)
    ajouter(db, a, "2026-10-01", 40, 11000)
    ajouter(db, b, "2026-10-01", 40, 5000)

    def lister(**filtres):
        base = dict(vehicule_id=None, chauffeur_id=None, type_carburant=None,
                    date_debut=None, date_fin=None, skip=0, limit=50, db=db)
        base.update(filtres)
        return svc.lister_pleins(**base)

    assert len(lister()) == 4
    assert len(lister(vehicule_id=a)) == 3
    assert len(lister(vehicule_id=a, date_debut=date(2026, 9, 10))) == 2
    assert len(lister(limit=1)) == 1


def test_ut_car_31_suppression_plein_existant(db):
    vid = nouveau_vehicule(db)
    plein = ajouter(db, vid, "2026-10-17", 40, 10000)
    svc.supprimer_plein(plein.id, db)
    with pytest.raises(HTTPException) as e:
        svc.lire_plein(plein.id, db)
    assert e.value.status_code == 404


# ------------------------------------ communication avec les autres services

class FausseReponse:
    def __init__(self, status_code):
        self.status_code = status_code
        self.text = ""


def test_ut_car_36_vehicule_introuvable_ne_cree_aucun_plein(db):
    with pytest.raises(HTTPException) as e:
        ajouter(db, 999, "2026-10-17", 40, 10000)
    assert e.value.status_code == 404
    assert db.query(models.Carburant).count() == 0


def test_ut_car_37_service_vehicule_en_panne_donne_503(db):
    vid = nouveau_vehicule(db)
    db.services.en_panne.add("Vehicule")
    with pytest.raises(HTTPException) as e:
        ajouter(db, vid, "2026-10-17", 40, 10000)
    assert e.value.status_code == 503
    assert db.query(models.Carburant).count() == 0


def test_ut_car_38_service_chauffeur_en_panne_donne_503(db):
    vid = nouveau_vehicule(db)
    cid = nouveau_chauffeur(db)
    db.services.en_panne.add("Chauffeur")
    with pytest.raises(HTTPException) as e:
        ajouter(db, vid, "2026-10-17", 40, 10000, chauffeur_id=cid)
    assert e.value.status_code == 503


def test_ut_car_39_client_http_traduit_les_reponses(monkeypatch):
    # clients._verifier est le vrai code: seul httpx.get est simule
    monkeypatch.setattr(clients.httpx, "get", lambda url, headers=None, timeout=None: FausseReponse(200))
    clients._verifier("http://x", "/vehicules/{id}", 1, "Vehicule")  # aucune exception

    monkeypatch.setattr(clients.httpx, "get", lambda url, headers=None, timeout=None: FausseReponse(404))
    with pytest.raises(HTTPException) as e:
        clients._verifier("http://x", "/vehicules/{id}", 1, "Vehicule")
    assert e.value.status_code == 404

    monkeypatch.setattr(clients.httpx, "get", lambda url, headers=None, timeout=None: FausseReponse(500))
    with pytest.raises(HTTPException) as e:
        clients._verifier("http://x", "/vehicules/{id}", 1, "Vehicule")
    assert e.value.status_code == 503


def test_ut_car_40_client_http_injoignable_donne_503(monkeypatch):
    def en_panne(url, headers=None, timeout=None):
        raise httpx.ConnectError("connexion refusee")

    monkeypatch.setattr(clients.httpx, "get", en_panne)
    with pytest.raises(HTTPException) as e:
        clients._verifier("http://x", "/vehicules/{id}", 1, "Vehicule")
    assert e.value.status_code == 503


def test_ut_car_43_sync_kilometrage_appelle_patch_du_service_vehicule(monkeypatch):
    vu = {}

    def faux_patch(url, headers=None, json=None, timeout=None):
        vu.update(url=url, headers=headers, json=json)
        return FausseReponse(200)

    monkeypatch.setattr(clients.httpx, "patch", faux_patch)
    monkeypatch.setenv("SERVICE_API_KEY", "cle-service-tests")

    assert clients.synchroniser_kilometrage_vehicule(7, 45650) is True
    assert vu["url"] == "http://vehicule-service/vehicules/7/kilometrage"
    assert vu["json"] == {"kilometrage": 45650}
    assert vu["headers"]["X-API-Key"] == "cle-service-tests"


def test_ut_car_44_sync_kilometrage_echoue_sans_lever_d_exception(monkeypatch):
    monkeypatch.setattr(
        clients.httpx, "patch", lambda url, headers=None, json=None, timeout=None: FausseReponse(500)
    )
    assert clients.synchroniser_kilometrage_vehicule(7, 45650) is False

    def en_panne(url, headers=None, json=None, timeout=None):
        raise httpx.ConnectError("connexion refusee")

    monkeypatch.setattr(clients.httpx, "patch", en_panne)
    assert clients.synchroniser_kilometrage_vehicule(7, 45650) is False


# ------------------------- regles de gestion pas encore developpees (non executees)

@pytest.mark.skip(reason="RG-02 non developpee: la capacite du reservoir vient du module Vehicule")
def test_ut_car_32_alerte_reservoir():
    ...


@pytest.mark.skip(reason="RG-03 non developpee: le plein n'a qu'une date, pas d'heure")
def test_ut_car_33_anti_doublon_15_minutes():
    ...


def test_ut_car_34_droit_de_saisie_chauffeur_gestionnaire(db):
    vid = nouveau_vehicule(db)
    cid = nouveau_chauffeur(db)
    db.services.affectations[cid] = vid
    chauffeur = {"role": "CHAUFFEUR", "id_chauffeur": cid}
    with pytest.raises(HTTPException) as e:
        svc.creer_plein(donnees(vid, "2026-10-17", 40, 10000, chauffeur_id=cid), db, chauffeur)
    assert e.value.status_code == 403

    gestionnaire = {"role": "GESTIONNAIRE", "sub": "gestionnaire-1"}
    plein = svc.creer_plein(donnees(vid, "2026-10-18", 42, 10600, chauffeur_id=cid), db, gestionnaire)
    assert plein.id is not None


def test_ut_car_35_lecture_seule_du_chauffeur(db):
    vid = nouveau_vehicule(db)
    cid1 = nouveau_chauffeur(db)
    cid2 = nouveau_chauffeur(db)
    db.services.affectations[cid1] = vid
    db.services.affectations[cid2] = vid
    ajouter(db, vid, "2026-10-17", 40, 10000, chauffeur_id=cid1)
    ajouter(db, vid, "2026-10-18", 42, 10600, chauffeur_id=cid2)

    auth_chauffeur = {"role": "CHAUFFEUR", "id_chauffeur": cid1}
    pleins = svc.lister_pleins(
        vehicule_id=None, chauffeur_id=cid2, type_carburant=None,
        date_debut=None, date_fin=None, skip=0, limit=50, db=db,
        auth=auth_chauffeur,
    )
    assert len(pleins) == 1
    assert all(plein.chauffeur_id == cid1 for plein in pleins)
    with pytest.raises(HTTPException) as e:
        svc.modifier_plein(pleins[0].id, schemas.CarburantUpdate(quantite_litres=50), db, auth_chauffeur)
    assert e.value.status_code == 403


def test_ut_car_41_client_http_envoie_la_cle_de_service(monkeypatch):
    recu = {}

    def espion(url, headers=None, timeout=None):
        recu["headers"] = headers
        return FausseReponse(200)

    monkeypatch.setenv("SERVICE_API_KEY", "ma-cle")
    monkeypatch.setattr(clients.httpx, "get", espion)
    clients._verifier("http://x", "/vehicules/{id}", 1, "Vehicule")
    assert recu["headers"] == {"X-API-Key": "ma-cle"}


def test_ut_car_42_client_http_sans_cle_n_envoie_pas_d_en_tete(monkeypatch):
    recu = {}

    def espion(url, headers=None, timeout=None):
        recu["headers"] = headers
        return FausseReponse(200)

    monkeypatch.delenv("SERVICE_API_KEY", raising=False)
    monkeypatch.setattr(clients.httpx, "get", espion)
    clients._verifier("http://x", "/vehicules/{id}", 1, "Vehicule")
    assert recu["headers"] == {}