import pytest
import time
import os
from fastapi.testclient import TestClient
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker
from sqlalchemy.pool import StaticPool

from database import Base, get_db
from main import app
import auth


@pytest.fixture()
def client():
    engine = create_engine(
        "sqlite://",
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    Base.metadata.create_all(bind=engine)
    Session = sessionmaker(bind=engine, autocommit=False, autoflush=False)

    def override():
        db = Session()
        try:
            yield db
        finally:
            db.close()

    app.dependency_overrides[get_db] = override
    test_client = TestClient(app)
    test_client.headers.update({"Authorization": f"Bearer {auth.issue_token_admin()}"})
    yield test_client
    app.dependency_overrides.clear()
    engine.dispose()


def preparer(services):
    """Declare un vehicule et un chauffeur connus des (faux) services."""
    vid = services.nouveau_vehicule()
    cid = services.nouveau_chauffeur()
    services.affectations[cid] = vid
    return vid, cid


def plein(vid, cid, jour, litres, km, prix=11.5, **extra):
    return {
        "vehicule_id": vid,
        "chauffeur_id": cid,
        "date_plein": jour,
        "quantite_litres": litres,
        "prix_litre": prix,
        "kilometrage": km,
        "type_carburant": "diesel",
        **extra,
    }


def test_creation_calcule_le_montant(client, services):
    vid, cid = preparer(services)
    r = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    assert r.status_code == 201
    assert r.json()["montant_total"] == 460.0


def test_references_inconnues(client, services):
    r = client.post("/carburants", json=plein(999, None, "2026-10-01", 40, 10000))
    assert r.status_code == 404
    vid, _ = preparer(services)
    r = client.post("/carburants", json=plein(vid, 999, "2026-10-01", 40, 10000))
    assert r.status_code == 404


def test_liste_vehicules_de_reference(client, monkeypatch):
    vehicules = [
        {"id": 1, "immatriculation": "12345-A-6"},
        {"id": 2, "immatriculation": "67890-B-1"},
    ]
    monkeypatch.setattr("routers.carburant.clients.lister_vehicules", lambda: vehicules)
    response = client.get("/carburants/references/vehicules")
    assert response.status_code == 200
    assert response.json() == vehicules


def test_liste_chauffeurs_de_reference(client, monkeypatch):
    chauffeurs = [
        {"id_chauffeur": 1, "nom": "Alami", "prenom": "Youssef"},
        {"id_chauffeur": 2, "nom": "Bennani", "prenom": "Salma"},
    ]
    monkeypatch.setattr("routers.carburant.clients.lister_chauffeurs", lambda: chauffeurs)
    response = client.get("/carburants/references/chauffeurs")
    assert response.status_code == 200
    assert response.json() == chauffeurs


def test_dernier_entretien_de_reference(client, monkeypatch):
    entretien = {
        "date_entretien": "2026-10-12",
        "type": "PREVENTIF",
        "categorie": "VIDANGE",
    }
    monkeypatch.setattr("routers.carburant.clients.dernier_entretien_vehicule", lambda vid: entretien)
    response = client.get("/carburants/references/vehicules/1/dernier-entretien")
    assert response.status_code == 200
    assert response.json() == entretien


def test_dernier_entretien_est_limite_au_vehicule_du_chauffeur(client, monkeypatch):
    monkeypatch.setattr(
        "routers.carburant.clients.dernier_entretien_vehicule",
        lambda vid: pytest.fail("un chauffeur ne doit pas voir l'entretien d'un autre vehicule"),
    )
    client.headers["Authorization"] = f"Bearer {token_chauffeur(12)}"
    response = client.get("/carburants/references/vehicules/99/dernier-entretien")
    assert response.status_code == 200
    assert response.json() is None


def test_service_vehicule_indisponible(client, services):
    vid, cid = preparer(services)
    services.en_panne.add("Vehicule")
    r = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    assert r.status_code == 503
    services.en_panne.clear()
    assert client.get("/carburants").json() == []  # rien n'a ete enregistre


def test_validation_valeurs(client, services):
    vid, cid = preparer(services)
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-01", -5, 10000)).status_code == 422
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, -1)).status_code == 422
    bad = plein(vid, cid, "2026-10-01", 40, 10000)
    bad["type_carburant"] = "kerosene"
    assert client.post("/carburants", json=bad).status_code == 422


def test_kilometrage_ne_peut_pas_reculer(client, services):
    vid, cid = preparer(services)
    client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    client.post("/carburants", json=plein(vid, cid, "2026-10-20", 40, 10800))
    # plus tard mais compteur plus bas
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-25", 40, 10500)).status_code == 400
    # meme compteur que le plein precedent: refuse aussi (strictement croissant)
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-25", 40, 10800)).status_code == 400
    # entre deux pleins avec un compteur trop haut
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-10", 40, 11000)).status_code == 400
    # entre deux pleins, valeur coherente
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-10", 40, 10400)).status_code == 201


def test_liste_filtres_et_pagination(client, services):
    vid, cid = preparer(services)
    for i, jour in enumerate(["2026-09-01", "2026-09-15", "2026-10-01"]):
        client.post("/carburants", json=plein(vid, cid, jour, 40, 10000 + i * 500))
    assert len(client.get("/carburants").json()) == 3
    assert len(client.get("/carburants", params={"date_debut": "2026-09-10"}).json()) == 2
    assert len(client.get("/carburants", params={"limit": 1}).json()) == 1
    assert client.get("/carburants", params={"vehicule_id": 999}).json() == []


def test_modification_recalcule_le_montant(client, services):
    vid, cid = preparer(services)
    pid = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000)).json()["id"]
    r = client.patch(f"/carburants/{pid}", json={"quantite_litres": 50})
    assert r.status_code == 200
    assert r.json()["montant_total"] == 575.0
    assert client.patch(f"/carburants/{pid}", json={"prix_litre": None}).status_code == 422


def test_suppression(client, services):
    vid, cid = preparer(services)
    pid = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000)).json()["id"]
    assert client.delete(f"/carburants/{pid}").status_code == 204
    assert client.get(f"/carburants/{pid}").status_code == 404


def test_statistiques(client, services):
    vid, cid = preparer(services)
    client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    client.post("/carburants", json=plein(vid, cid, "2026-10-10", 36, 10600))
    client.post("/carburants", json=plein(vid, cid, "2026-10-20", 30, 11100))
    s = client.get(f"/carburants/stats/vehicules/{vid}").json()
    assert s["nombre_pleins"] == 3
    assert s["total_litres"] == 106
    assert s["distance_km"] == 1100
    # (36 + 30) litres pour 1100 km
    assert s["consommation_l_100km"] == 6.0
    assert client.get("/carburants/stats/vehicules/999").status_code == 404


def test_statistiques_sans_donnees(client, services):
    vid, _ = preparer(services)
    s = client.get(f"/carburants/stats/vehicules/{vid}").json()
    assert s["nombre_pleins"] == 0
    assert s["consommation_l_100km"] is None


# ---------- Alerte de surconsommation ----------

def trois_pleins_normaux(client, vid, cid):
    """Vehicule qui consomme toujours 7 L/100 km (42 L pour 600 km)."""
    client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    client.post("/carburants", json=plein(vid, cid, "2026-10-08", 42, 10600))
    client.post("/carburants", json=plein(vid, cid, "2026-10-15", 42, 11200))


def test_consommation_stockee_sur_chaque_plein(client, services):
    vid, cid = preparer(services)
    trois_pleins_normaux(client, vid, cid)
    pleins = client.get("/carburants", params={"vehicule_id": vid}).json()
    consos = [p["consommation_l_100km"] for p in reversed(pleins)]
    assert consos == [None, 7.0, 7.0]
    assert not any(p["anomalie"] for p in pleins)


def test_alerte_surconsommation_quand_conso_double(client, services):
    vid, cid = preparer(services)
    trois_pleins_normaux(client, vid, cid)
    # Meme distance mais le double de litres: 84 L pour 600 km = 14 L/100 km
    client.post("/carburants", json=plein(vid, cid, "2026-10-22", 84, 11800))
    anomalies = client.get("/carburants/anomalies").json()
    assert len(anomalies) == 1
    assert anomalies[0]["consommation_l_100km"] == 14.0
    assert anomalies[0]["anomalie"] is True
    assert "Surconsommation" in anomalies[0]["message_anomalie"]


def test_pas_d_alerte_pour_une_petite_variation(client, services):
    vid, cid = preparer(services)
    trois_pleins_normaux(client, vid, cid)
    # 7.7 L/100 km = +10 %, sous le seuil de 30 %
    client.post("/carburants", json=plein(vid, cid, "2026-10-22", 46.2, 11800))
    assert client.get("/carburants/anomalies").json() == []


def test_pas_d_alerte_sans_historique_suffisant(client, services):
    vid, cid = preparer(services)
    client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    client.post("/carburants", json=plein(vid, cid, "2026-10-08", 84, 10600))  # 14 L/100 km
    assert client.get("/carburants/anomalies").json() == []


def test_alerte_retiree_si_le_plein_est_corrige_ou_supprime(client, services):
    vid, cid = preparer(services)
    trois_pleins_normaux(client, vid, cid)
    pid = client.post("/carburants", json=plein(vid, cid, "2026-10-22", 84, 11800)).json()["id"]
    assert len(client.get("/carburants/anomalies").json()) == 1
    # correction d'une erreur de saisie: 42 L au lieu de 84
    client.patch(f"/carburants/{pid}", json={"quantite_litres": 42})
    assert client.get("/carburants/anomalies").json() == []
    # on remet une valeur anormale puis on supprime
    client.patch(f"/carburants/{pid}", json={"quantite_litres": 84})
    assert len(client.get("/carburants/anomalies").json()) == 1
    client.delete(f"/carburants/{pid}")
    assert client.get("/carburants/anomalies").json() == []


def test_anomalies_filtre_par_vehicule(client, services):
    vid, cid = preparer(services)
    trois_pleins_normaux(client, vid, cid)
    client.post("/carburants", json=plein(vid, cid, "2026-10-22", 84, 11800))
    assert len(client.get("/carburants/anomalies", params={"vehicule_id": vid}).json()) == 1
    assert client.get("/carburants/anomalies", params={"vehicule_id": 999}).json() == []


def test_interface_web_servie(client):
    r = client.get("/ui/")
    assert r.status_code == 200
    assert "Carburant" in r.text
    assert client.get("/ui/app.js").status_code == 200
    assert client.get("/ui/style.css").status_code == 200


def token_chauffeur(chauffeur_id):
    now = int(time.time())
    payload = {"sub": str(chauffeur_id), "role": "CHAUFFEUR", "id_chauffeur": chauffeur_id, "exp": now + 3600}
    import base64, hashlib, hmac, json
    b64 = lambda value: base64.urlsafe_b64encode(value).rstrip(b"=").decode()
    head = b64(json.dumps({"typ": "JWT", "alg": "HS256"}).encode())
    body = b64(json.dumps(payload).encode())
    secret = os.environ["JWT_SECRET"].encode()
    sig = b64(hmac.new(secret, f"{head}.{body}".encode(), hashlib.sha256).digest())
    return f"{head}.{body}.{sig}"


def test_401_sans_jeton(client):
    client.headers.pop("Authorization", None)
    assert client.get("/carburants").status_code == 401


def test_chauffeur_ne_voit_que_ses_pleins(client, services):
    vid, cid1 = preparer(services)
    cid2 = services.nouveau_chauffeur()
    services.affectations[cid2] = vid
    client.post("/carburants", json=plein(vid, cid1, "2026-10-01", 40, 10000))
    client.post("/carburants", json=plein(vid, cid2, "2026-10-08", 42, 10600))
    client.headers["Authorization"] = f"Bearer {token_chauffeur(cid1)}"
    r = client.get("/carburants", params={"chauffeur_id": cid2})
    assert r.status_code == 200
    assert len(r.json()) == 1
    assert all(p["chauffeur_id"] == cid1 for p in r.json())


def test_chauffeur_recevoirait_403_sur_mutations(client, services):
    vid, cid = preparer(services)
    pid = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000)).json()["id"]
    client.headers["Authorization"] = f"Bearer {token_chauffeur(cid)}"
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-08", 42, 10600)).status_code == 403
    assert client.patch(f"/carburants/{pid}", json={"quantite_litres": 50}).status_code == 403
    assert client.delete(f"/carburants/{pid}").status_code == 403


def test_cle_service_accepte_post(client, services):
    vid, cid = preparer(services)
    client.headers.pop("Authorization", None)
    r = client.post(
        "/carburants",
        json=plein(vid, cid, "2026-10-01", 40, 10000),
        headers={"X-Service-Key": "cle-service-tests"},
    )
    assert r.status_code == 201


def test_administrateur_voit_tout(client, services):
    vid, cid1 = preparer(services)
    cid2 = services.nouveau_chauffeur()
    services.affectations[cid2] = vid
    client.post("/carburants", json=plein(vid, cid1, "2026-10-01", 40, 10000))
    client.post("/carburants", json=plein(vid, cid2, "2026-10-08", 42, 10600))
    r = client.get("/carburants")
    assert r.status_code == 200
    assert len(r.json()) == 2


def test_affectation_couple_coherent_accepte(client, services):
    vid, cid = preparer(services)
    r = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    assert r.status_code == 201


def test_affectation_couple_incoherent_refuse(client, services):
    vid, cid = preparer(services)
    autre_vehicule = services.nouveau_vehicule()
    r = client.post("/carburants", json=plein(autre_vehicule, cid, "2026-10-01", 40, 10000))
    assert r.status_code == 400
    assert r.json()["detail"] == "Ce chauffeur n'est pas affecté à ce véhicule"


def test_affectation_chauffeur_sans_vehicule_refuse(client, services):
    vid = services.nouveau_vehicule()
    cid = services.nouveau_chauffeur()
    r = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    assert r.status_code == 400
    assert r.json()["detail"] == "Ce chauffeur n'est pas affecté à ce véhicule"


def test_cle_service_applique_aussi_affectation(client, services):
    vid, cid = preparer(services)
    autre_vehicule = services.nouveau_vehicule()
    client.headers.pop("Authorization", None)
    r = client.post(
        "/carburants",
        json=plein(autre_vehicule, cid, "2026-10-01", 40, 10000),
        headers={"X-API-Key": "cle-service-tests"},
    )
    assert r.status_code == 400
    assert r.json()["detail"] == "Ce chauffeur n'est pas affecté à ce véhicule"


def test_patch_verifie_aussi_affectation(client, services):
    vid, cid = preparer(services)
    autre_vehicule = services.nouveau_vehicule()
    pid = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000)).json()["id"]
    r = client.patch(f"/carburants/{pid}", json={"vehicule_id": autre_vehicule})
    assert r.status_code == 400
    assert r.json()["detail"] == "Ce chauffeur n'est pas affecté à ce véhicule"


def test_kilometrage_meme_jour_egal_refuse(client, services):
    vid, cid = preparer(services)
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000)).status_code == 201
    r = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    assert r.status_code == 400


def test_kilometrage_meme_jour_inferieur_refuse(client, services):
    vid, cid = preparer(services)
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10100)).status_code == 201
    r = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10050))
    assert r.status_code == 400


def test_kilometrage_meme_jour_superieur_accepte(client, services):
    vid, cid = preparer(services)
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10100)).status_code == 201
    r = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10200))
    assert r.status_code == 201


def test_chauffeur_detail_autre_chauffeur_404(client, services):
    vid, cid1 = preparer(services)
    cid2 = services.nouveau_chauffeur()
    services.affectations[cid2] = vid
    pid1 = client.post("/carburants", json=plein(vid, cid1, "2026-10-01", 40, 10000)).json()["id"]
    pid2 = client.post("/carburants", json=plein(vid, cid2, "2026-10-08", 42, 10600)).json()["id"]
    client.headers["Authorization"] = f"Bearer {token_chauffeur(cid1)}"
    assert client.get(f"/carburants/{pid2}").status_code == 404
    assert client.get(f"/carburants/{pid1}").status_code == 200


def test_chauffeur_anomalies_stats_sont_isoles(client, services):
    vid1, cid1 = preparer(services)
    vid2 = services.nouveau_vehicule()
    cid2 = services.nouveau_chauffeur()
    services.affectations[cid2] = vid2
    client.post("/carburants", json=plein(vid1, cid1, "2026-10-01", 40, 10000))
    client.post("/carburants", json=plein(vid2, cid2, "2026-10-01", 84, 10000))
    client.headers["Authorization"] = f"Bearer {token_chauffeur(cid1)}"
    assert client.get("/carburants/anomalies").json() == []
    stats = client.get(f"/carburants/stats/vehicules/{vid1}")
    assert stats.status_code == 200
    assert stats.json()["nombre_pleins"] == 1
    assert client.get(f"/carburants/stats/vehicules/{vid2}").json()["nombre_pleins"] == 0


def test_role_inconnu_refuse(client):
    now = int(time.time())
    payload = {"sub": "inconnu", "role": "INCONNU", "exp": now + 3600}
    import base64, hashlib, hmac, json
    b64 = lambda value: base64.urlsafe_b64encode(value).rstrip(b"=").decode()
    head = b64(json.dumps({"typ": "JWT", "alg": "HS256"}).encode())
    body = b64(json.dumps(payload).encode())
    secret = os.environ["JWT_SECRET"].encode()
    sig = b64(hmac.new(secret, f"{head}.{body}".encode(), hashlib.sha256).digest())
    token = f"{head}.{body}.{sig}"
    client.headers["Authorization"] = f"Bearer {token}"
    assert client.get("/carburants").status_code == 403
