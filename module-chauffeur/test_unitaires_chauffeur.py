"""Tests unitaires du module Chauffeur (UT-CHA-01 à UT-CHA-46).

Lancer : pytest -v --junitxml=rapport_tests_unitaires_chauffeur.xml
"""
import itertools
from datetime import date, timedelta

import pytest
from fastapi import HTTPException
from pydantic import ValidationError

import models
import regles
import schemas
from models import StatutChauffeur as S
from routers import chauffeurs as svc

AUJOURDHUI = date(2026, 11, 8)          # date fixe pour les règles pures
FUTUR = date.today() + timedelta(days=365)
PASSE = date.today() - timedelta(days=30)

_compteur = itertools.count(1)


# ---------------------------------------------------------------- utilitaires
def donnees(**surcharge):
    """Données valides d'un chauffeur (CIN et permis uniques à chaque appel)."""
    n = next(_compteur)
    base = {
        "cin": f"AB{100000 + n}",
        "nom": "Alaoui",
        "prenom": "Sara",
        "telephone": "0612345678",
        "numero_permis": f"P-{n:04d}",
        "categorie_permis": "B",
        "date_expiration_permis": FUTUR,
    }
    base.update(surcharge)
    return base


def creer(db, **surcharge):
    """Crée un chauffeur via la vraie fonction du routeur."""
    return svc.creer_chauffeur(schemas.ChauffeurCreate(**donnees(**surcharge)), db)


def forcer(db, chauffeur, statut=None, date_permis=None):
    """Force un état en base (simule un état préalable)."""
    if statut is not None:
        chauffeur.statut = statut
    if date_permis is not None:
        chauffeur.date_expiration_permis = date_permis
    db.commit()


def erreur(exc_info):
    return exc_info.value.detail["erreur"]


# ======================================================================
# 1. Validation des données (schemas.py)
# ======================================================================
def test_ut_cha_01_chauffeur_valide_accepte_et_normalise():
    c = schemas.ChauffeurCreate(**donnees(
        cin=" ab123456 ", nom="  Alaoui ", telephone="06 12 34 56 78",
        categorie_permis="b"))
    assert c.cin == "AB123456"
    assert c.nom == "Alaoui"
    assert c.telephone == "0612345678"
    assert c.categorie_permis == "B"


@pytest.mark.parametrize("cin", ["123", "A1234", "ABC123456", "AB12345678", "AB12A456"])
def test_ut_cha_02_cin_invalide_refuse(cin):
    with pytest.raises(ValidationError):
        schemas.ChauffeurCreate(**donnees(cin=cin))


@pytest.mark.parametrize("tel", ["12345", "0512345678", "06123", "abcdefghij", "+33612345678"])
def test_ut_cha_03_telephone_invalide_refuse(tel):
    with pytest.raises(ValidationError):
        schemas.ChauffeurCreate(**donnees(telephone=tel))


def test_ut_cha_04_telephone_valide_ou_absent_accepte():
    for tel in ["0612345678", "0712345678", "+212612345678", "+212712345678"]:
        assert schemas.ChauffeurCreate(**donnees(telephone=tel)).telephone == tel
    sans_tel = donnees()
    del sans_tel["telephone"]
    assert schemas.ChauffeurCreate(**sans_tel).telephone is None


def test_ut_cha_05_categorie_hors_liste_refusee():
    with pytest.raises(ValidationError):
        schemas.ChauffeurCreate(**donnees(categorie_permis="Z"))


@pytest.mark.parametrize("champ", ["cin", "nom", "prenom", "numero_permis",
                                   "categorie_permis", "date_expiration_permis"])
def test_ut_cha_06_champ_obligatoire_manquant_refuse(champ):
    d = donnees()
    del d[champ]
    with pytest.raises(ValidationError):
        schemas.ChauffeurCreate(**d)


@pytest.mark.parametrize("champ", ["nom", "prenom", "numero_permis"])
def test_ut_cha_07_champ_vide_refuse(champ):
    with pytest.raises(ValidationError):
        schemas.ChauffeurCreate(**donnees(**{champ: "   "}))


def test_ut_cha_08_modification_partielle_acceptee():
    u = schemas.ChauffeurUpdate(telephone="07 11 22 33 44")
    assert u.model_dump(exclude_unset=True) == {"telephone": "0711223344"}


# ======================================================================
# 2. Unicité et création (routers/chauffeurs.py)
# ======================================================================
def test_ut_cha_09_cin_en_double_refuse(db):
    c = creer(db)
    with pytest.raises(HTTPException) as e:
        svc.verifier_unicite(db, c.cin, None)
    assert e.value.status_code == 409
    assert erreur(e) == "CIN_DEJA_UTILISE"


def test_ut_cha_10_numero_permis_en_double_refuse(db):
    c = creer(db)
    with pytest.raises(HTTPException) as e:
        svc.verifier_unicite(db, None, c.numero_permis)
    assert e.value.status_code == 409
    assert erreur(e) == "PERMIS_DEJA_UTILISE"


def test_ut_cha_11_modification_conserve_son_propre_cin(db):
    c = creer(db)
    out = svc.modifier_chauffeur(
        c.id_chauffeur, schemas.ChauffeurUpdate(cin=c.cin, nom="Nouveau"), db)
    assert out.nom == "Nouveau"
    assert out.cin == c.cin


def test_ut_cha_12_doublon_avec_chauffeur_inactif_signale_reactivation(db):
    c = creer(db)
    svc.supprimer_chauffeur(c.id_chauffeur, db)
    with pytest.raises(HTTPException) as e:
        svc.verifier_unicite(db, c.cin, None)
    assert e.value.status_code == 409
    assert erreur(e) == "CHAUFFEUR_INACTIF_EXISTANT"
    assert e.value.detail["id_chauffeur"] == c.id_chauffeur


def test_ut_cha_13_creation_statut_disponible_par_defaut(db):
    c = creer(db, cin="ab654321")
    assert c.id_chauffeur is not None
    assert c.statut == S.DISPONIBLE
    assert c.cin == "AB654321"


def test_ut_cha_14_creation_avec_permis_expire_refusee(db):
    for date_exp in (PASSE, date.today()):
        with pytest.raises(HTTPException) as e:
            creer(db, date_expiration_permis=date_exp)
        assert e.value.status_code == 422
        assert erreur(e) == "PERMIS_EXPIRE"
    assert db.query(models.Chauffeur).count() == 0


# ======================================================================
# 3. Validité du permis (regles.py)
# ======================================================================
def test_ut_cha_15_permis_valide_si_date_future():
    assert regles.permis_est_valide(AUJOURDHUI + timedelta(days=1), AUJOURDHUI) is True


def test_ut_cha_16_permis_invalide_le_jour_meme_ou_avant():
    assert regles.permis_est_valide(AUJOURDHUI, AUJOURDHUI) is False
    assert regles.permis_est_valide(AUJOURDHUI - timedelta(days=1), AUJOURDHUI) is False


def test_ut_cha_17_passage_disponible_refuse_si_permis_expire():
    with pytest.raises(HTTPException) as e:
        regles.verifier_changement_statut(S.EN_CONGE, S.DISPONIBLE, AUJOURDHUI, AUJOURDHUI)
    assert e.value.status_code == 409
    assert erreur(e) == "PERMIS_EXPIRE"


def test_ut_cha_18_passage_en_mission_refuse_si_permis_expire():
    with pytest.raises(HTTPException) as e:
        regles.verifier_changement_statut(S.DISPONIBLE, S.EN_MISSION,
                                          AUJOURDHUI - timedelta(days=10), AUJOURDHUI)
    assert e.value.status_code == 409
    assert erreur(e) == "PERMIS_EXPIRE"


def test_ut_cha_19_passage_en_conge_autorise_meme_si_permis_expire():
    regles.verifier_changement_statut(S.DISPONIBLE, S.EN_CONGE,
                                      AUJOURDHUI - timedelta(days=10), AUJOURDHUI)


# ======================================================================
# 4. Machine à états (regles.py et routeur)
# ======================================================================
def test_ut_cha_20_disponible_vers_en_mission_autorise():
    regles.verifier_changement_statut(S.DISPONIBLE, S.EN_MISSION,
                                      AUJOURDHUI + timedelta(days=100), AUJOURDHUI)


def test_ut_cha_21_en_conge_vers_en_mission_refuse():
    with pytest.raises(HTTPException) as e:
        regles.verifier_changement_statut(S.EN_CONGE, S.EN_MISSION,
                                          AUJOURDHUI + timedelta(days=100), AUJOURDHUI)
    assert erreur(e) == "TRANSITION_INTERDITE"


def test_ut_cha_22_en_mission_vers_en_conge_refuse():
    with pytest.raises(HTTPException) as e:
        regles.verifier_changement_statut(S.EN_MISSION, S.EN_CONGE,
                                          AUJOURDHUI + timedelta(days=100), AUJOURDHUI)
    assert e.value.status_code == 409
    assert erreur(e) == "MISSION_NON_CLOTUREE"


def test_ut_cha_23_en_mission_vers_inactif_refuse():
    with pytest.raises(HTTPException) as e:
        regles.verifier_changement_statut(S.EN_MISSION, S.INACTIF,
                                          AUJOURDHUI + timedelta(days=100), AUJOURDHUI)
    assert e.value.status_code == 409
    assert erreur(e) == "MISSION_NON_CLOTUREE"


def test_ut_cha_24_en_mission_vers_disponible_autorise():
    regles.verifier_changement_statut(S.EN_MISSION, S.DISPONIBLE,
                                      AUJOURDHUI + timedelta(days=100), AUJOURDHUI)


def test_ut_cha_25_meme_statut_refuse():
    with pytest.raises(HTTPException) as e:
        regles.verifier_changement_statut(S.DISPONIBLE, S.DISPONIBLE,
                                          AUJOURDHUI + timedelta(days=100), AUJOURDHUI)
    assert erreur(e) == "STATUT_IDENTIQUE"


def test_ut_cha_26_changement_de_statut_enregistre_en_base(db):
    c = creer(db)
    svc.changer_statut(c.id_chauffeur, schemas.ChauffeurStatutUpdate(statut=S.EN_CONGE), db)
    db.expire_all()
    relu = db.get(models.Chauffeur, c.id_chauffeur)
    assert relu.statut == S.EN_CONGE


# ======================================================================
# 5. Consultation et suppression logique (routers/chauffeurs.py)
# ======================================================================
def test_ut_cha_27_lecture_chauffeur_actif(db):
    c = creer(db)
    lu = svc.lire_chauffeur(c.id_chauffeur, db)
    assert lu.id_chauffeur == c.id_chauffeur
    assert lu.cin == c.cin


def test_ut_cha_28_lecture_chauffeur_inexistant_404_json(db):
    with pytest.raises(HTTPException) as e:
        svc.lire_chauffeur(999, db)
    assert e.value.status_code == 404
    assert isinstance(e.value.detail, dict)
    assert e.value.detail["erreur"] == "CHAUFFEUR_INTROUVABLE"
    assert "999" in e.value.detail["message"]


def test_ut_cha_29_lecture_chauffeur_inactif_404(db):
    c = creer(db)
    forcer(db, c, statut=S.INACTIF)
    with pytest.raises(HTTPException) as e:
        svc.lire_chauffeur(c.id_chauffeur, db)
    assert e.value.status_code == 404
    assert erreur(e) == "CHAUFFEUR_INTROUVABLE"


def test_ut_cha_30_suppression_logique_conserve_la_ligne(db):
    c = creer(db)
    id_c = c.id_chauffeur
    svc.supprimer_chauffeur(id_c, db)
    db.expire_all()
    ligne = db.get(models.Chauffeur, id_c)
    assert ligne is not None                    # pas de suppression physique
    assert ligne.statut == S.INACTIF
    assert db.query(models.Chauffeur).count() == 1


def test_ut_cha_31_suppression_refusee_si_en_mission(db):
    c = creer(db)
    forcer(db, c, statut=S.EN_MISSION)
    with pytest.raises(HTTPException) as e:
        svc.supprimer_chauffeur(c.id_chauffeur, db)
    assert e.value.status_code == 409
    assert erreur(e) == "CHAUFFEUR_EN_MISSION"
    db.expire_all()
    assert db.get(models.Chauffeur, c.id_chauffeur).statut == S.EN_MISSION


def test_ut_cha_32_liste_exclut_les_inactifs_et_filtre(db):
    b = creer(db, categorie_permis="B")
    c = creer(db, categorie_permis="C")
    inactif = creer(db, categorie_permis="B")
    forcer(db, inactif, statut=S.INACTIF)

    tous = svc.lister_chauffeurs(None, None, 0, 50, db)
    assert {x.id_chauffeur for x in tous} == {b.id_chauffeur, c.id_chauffeur}

    seulement_c = svc.lister_chauffeurs(None, "c", 0, 50, db)
    assert [x.id_chauffeur for x in seulement_c] == [c.id_chauffeur]


def test_ut_cha_33_reactivation_chauffeur_inactif(db):
    c = creer(db)
    svc.supprimer_chauffeur(c.id_chauffeur, db)
    out = svc.reactiver_chauffeur(c.id_chauffeur, None, db)
    assert out.statut == S.DISPONIBLE


def test_ut_cha_34_reactivation_refusee_si_permis_expire_puis_acceptee(db):
    c = creer(db)
    svc.supprimer_chauffeur(c.id_chauffeur, db)
    forcer(db, c, date_permis=PASSE)
    with pytest.raises(HTTPException) as e:
        svc.reactiver_chauffeur(c.id_chauffeur, None, db)
    assert erreur(e) == "PERMIS_EXPIRE"

    out = svc.reactiver_chauffeur(
        c.id_chauffeur, schemas.ChauffeurReactivation(date_expiration_permis=FUTUR), db)
    assert out.statut == S.DISPONIBLE
    assert out.date_expiration_permis == FUTUR


# ======================================================================
# 6. Compatibilité permis / véhicule et éligibilité
# ======================================================================
def test_ut_cha_35_permis_compatible_avec_la_categorie_du_vehicule(db):
    c = creer(db, categorie_permis="C")
    r = svc.verifier_compatibilite(c.id_chauffeur, "c", db)
    assert r["compatible"] is True
    assert r["categorie_vehicule"] == "C"


def test_ut_cha_36_permis_incompatible_avec_la_categorie_du_vehicule(db):
    c = creer(db, categorie_permis="B")
    r = svc.verifier_compatibilite(c.id_chauffeur, "C", db)
    assert r["compatible"] is False


def test_ut_cha_37_disponible_avec_permis_valide_selectionnable_et_affectable(db):
    c = creer(db)
    r = svc.verifier_eligibilite(c.id_chauffeur, db)
    assert r["selectionnable"] is True
    assert r["affectable"] is True
    assert r["motifs"] == []


def test_ut_cha_38_en_mission_selectionnable_mais_non_affectable(db):
    c = creer(db)
    forcer(db, c, statut=S.EN_MISSION)
    r = svc.verifier_eligibilite(c.id_chauffeur, db)
    assert r["selectionnable"] is True
    assert r["affectable"] is False
    assert "DEJA_EN_MISSION" in r["motifs"]


def test_ut_cha_39_en_conge_non_selectionnable(db):
    c = creer(db)
    forcer(db, c, statut=S.EN_CONGE)
    r = svc.verifier_eligibilite(c.id_chauffeur, db)
    assert r["selectionnable"] is False
    assert r["affectable"] is False
    assert "CHAUFFEUR_EN_CONGE" in r["motifs"]


def test_ut_cha_40_inactif_non_selectionnable(db):
    c = creer(db)
    svc.supprimer_chauffeur(c.id_chauffeur, db)
    r = svc.verifier_eligibilite(c.id_chauffeur, db)     # 200 et non 404
    assert r["selectionnable"] is False
    assert r["affectable"] is False
    assert "CHAUFFEUR_INACTIF" in r["motifs"]


def test_ut_cha_41_permis_expire_non_affectable(db):
    c = creer(db)
    forcer(db, c, date_permis=PASSE)                     # le permis expire après création
    r = svc.verifier_eligibilite(c.id_chauffeur, db)
    assert r["permis_valide"] is False
    assert r["affectable"] is False
    assert "PERMIS_EXPIRE" in r["motifs"]


def test_ut_cha_42_affectation_refusee_si_categorie_incompatible(db):
    c = creer(db, categorie_permis="B")
    with pytest.raises(HTTPException) as e:
        svc.affecter_mission(c.id_chauffeur,
                             schemas.AffectationRequest(categorie_vehicule="C"), db)
    assert e.value.status_code == 409
    assert erreur(e) == "CATEGORIE_INCOMPATIBLE"
    db.expire_all()
    assert db.get(models.Chauffeur, c.id_chauffeur).statut == S.DISPONIBLE


def test_ut_cha_43_affectation_puis_cloture_de_mission(db):
    c = creer(db, categorie_permis="C")
    en_mission = svc.affecter_mission(
        c.id_chauffeur, schemas.AffectationRequest(categorie_vehicule="C"), db)
    assert en_mission.statut == S.EN_MISSION
    libre = svc.cloturer_mission(c.id_chauffeur, db)
    assert libre.statut == S.DISPONIBLE


def test_ut_cha_44_cloture_refusee_si_pas_en_mission(db):
    c = creer(db)
    with pytest.raises(HTTPException) as e:
        svc.cloturer_mission(c.id_chauffeur, db)
    assert e.value.status_code == 409
    assert erreur(e) == "PAS_EN_MISSION"


def test_ut_cha_45_chauffeurs_disponibles_filtres_par_categorie_et_permis(db):
    b = creer(db, categorie_permis="B")
    c = creer(db, categorie_permis="C")
    expire = creer(db, categorie_permis="B")
    forcer(db, expire, date_permis=PASSE)
    en_conge = creer(db, categorie_permis="B")
    forcer(db, en_conge, statut=S.EN_CONGE)

    tous = svc.chauffeurs_disponibles(None, db)
    assert {x.id_chauffeur for x in tous} == {b.id_chauffeur, c.id_chauffeur}

    pour_camion_c = svc.chauffeurs_disponibles("C", db)
    assert [x.id_chauffeur for x in pour_camion_c] == [c.id_chauffeur]


def test_ut_cha_46_permis_expires_ou_bientot_expires(db):
    expire = creer(db)
    forcer(db, expire, date_permis=PASSE)
    bientot = creer(db, date_expiration_permis=date.today() + timedelta(days=10))
    loin = creer(db, date_expiration_permis=date.today() + timedelta(days=200))

    ids = {x.id_chauffeur for x in svc.permis_expires(30, db)}
    assert ids == {expire.id_chauffeur, bientot.id_chauffeur}
    assert loin.id_chauffeur not in ids
