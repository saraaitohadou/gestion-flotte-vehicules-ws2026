import pytest
from fastapi.testclient import TestClient
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker
from sqlalchemy.pool import StaticPool

from database import Base, get_db
from main import app


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
    yield TestClient(app)
    app.dependency_overrides.clear()


def preparer(client):
    v = client.post("/vehicules", json={"immatriculation": "12345-A-6", "marque": "Dacia", "modele": "Logan"}).json()
    c = client.post("/chauffeurs", json={"nom": "Alaoui", "prenom": "Youssef"}).json()
    return v["id"], c["id"]


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


def test_creation_calcule_le_montant(client):
    vid, cid = preparer(client)
    r = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    assert r.status_code == 201
    assert r.json()["montant_total"] == 460.0


def test_references_inconnues(client):
    r = client.post("/carburants", json=plein(999, None, "2026-10-01", 40, 10000))
    assert r.status_code == 404
    vid, _ = preparer(client)
    r = client.post("/carburants", json=plein(vid, 999, "2026-10-01", 40, 10000))
    assert r.status_code == 404


def test_validation_valeurs(client):
    vid, cid = preparer(client)
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-01", -5, 10000)).status_code == 422
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, -1)).status_code == 422
    bad = plein(vid, cid, "2026-10-01", 40, 10000)
    bad["type_carburant"] = "kerosene"
    assert client.post("/carburants", json=bad).status_code == 422


def test_kilometrage_ne_peut_pas_reculer(client):
    vid, cid = preparer(client)
    client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    client.post("/carburants", json=plein(vid, cid, "2026-10-20", 40, 10800))
    # plus tard mais compteur plus bas
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-25", 40, 10500)).status_code == 422
    # entre deux pleins avec un compteur trop haut
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-10", 40, 11000)).status_code == 422
    # entre deux pleins, valeur coherente
    assert client.post("/carburants", json=plein(vid, cid, "2026-10-10", 40, 10400)).status_code == 201


def test_liste_filtres_et_pagination(client):
    vid, cid = preparer(client)
    for i, jour in enumerate(["2026-09-01", "2026-09-15", "2026-10-01"]):
        client.post("/carburants", json=plein(vid, cid, jour, 40, 10000 + i * 500))
    assert len(client.get("/carburants").json()) == 3
    assert len(client.get("/carburants", params={"date_debut": "2026-09-10"}).json()) == 2
    assert len(client.get("/carburants", params={"limit": 1}).json()) == 1
    assert client.get("/carburants", params={"vehicule_id": 999}).json() == []


def test_modification_recalcule_le_montant(client):
    vid, cid = preparer(client)
    pid = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000)).json()["id"]
    r = client.patch(f"/carburants/{pid}", json={"quantite_litres": 50})
    assert r.status_code == 200
    assert r.json()["montant_total"] == 575.0
    assert client.patch(f"/carburants/{pid}", json={"prix_litre": None}).status_code == 422


def test_suppression(client):
    vid, cid = preparer(client)
    pid = client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000)).json()["id"]
    assert client.delete(f"/carburants/{pid}").status_code == 204
    assert client.get(f"/carburants/{pid}").status_code == 404


def test_statistiques(client):
    vid, cid = preparer(client)
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


def test_statistiques_sans_donnees(client):
    vid, _ = preparer(client)
    s = client.get(f"/carburants/stats/vehicules/{vid}").json()
    assert s["nombre_pleins"] == 0
    assert s["consommation_l_100km"] is None


# ---------- Alerte de surconsommation ----------

def trois_pleins_normaux(client, vid, cid):
    """Vehicule qui consomme toujours 7 L/100 km (42 L pour 600 km)."""
    client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    client.post("/carburants", json=plein(vid, cid, "2026-10-08", 42, 10600))
    client.post("/carburants", json=plein(vid, cid, "2026-10-15", 42, 11200))


def test_consommation_stockee_sur_chaque_plein(client):
    vid, cid = preparer(client)
    trois_pleins_normaux(client, vid, cid)
    pleins = client.get("/carburants", params={"vehicule_id": vid}).json()
    consos = [p["consommation_l_100km"] for p in reversed(pleins)]
    assert consos == [None, 7.0, 7.0]
    assert not any(p["anomalie"] for p in pleins)


def test_alerte_surconsommation_quand_conso_double(client):
    vid, cid = preparer(client)
    trois_pleins_normaux(client, vid, cid)
    # Meme distance mais le double de litres: 84 L pour 600 km = 14 L/100 km
    # (3e consommation connue seulement apres 2 pleins normaux -> historique suffisant)
    client.post("/carburants", json=plein(vid, cid, "2026-10-22", 84, 11800))
    anomalies = client.get("/carburants/anomalies").json()
    assert len(anomalies) == 1
    assert anomalies[0]["consommation_l_100km"] == 14.0
    assert anomalies[0]["anomalie"] is True
    assert "Surconsommation" in anomalies[0]["message_anomalie"]


def test_pas_d_alerte_pour_une_petite_variation(client):
    vid, cid = preparer(client)
    trois_pleins_normaux(client, vid, cid)
    # 7.7 L/100 km = +10 %, sous le seuil de 30 %
    client.post("/carburants", json=plein(vid, cid, "2026-10-22", 46.2, 11800))
    assert client.get("/carburants/anomalies").json() == []


def test_pas_d_alerte_sans_historique_suffisant(client):
    vid, cid = preparer(client)
    client.post("/carburants", json=plein(vid, cid, "2026-10-01", 40, 10000))
    client.post("/carburants", json=plein(vid, cid, "2026-10-08", 84, 10600))  # 14 L/100 km
    assert client.get("/carburants/anomalies").json() == []


def test_alerte_retiree_si_le_plein_est_corrige_ou_supprime(client):
    vid, cid = preparer(client)
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


def test_anomalies_filtre_par_vehicule(client):
    vid, cid = preparer(client)
    trois_pleins_normaux(client, vid, cid)
    client.post("/carburants", json=plein(vid, cid, "2026-10-22", 84, 11800))
    assert len(client.get("/carburants/anomalies", params={"vehicule_id": vid}).json()) == 1
    assert client.get("/carburants/anomalies", params={"vehicule_id": 999}).json() == []
