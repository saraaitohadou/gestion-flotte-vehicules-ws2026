"""Tests d'authentification et de périmètre (aucun MySQL, aucun service externe)."""
from datetime import date, timedelta

import auth
import clients
import database
import main
import models
import schemas
from fastapi.testclient import TestClient
from routers import chauffeurs as svc


def _chauffeur(db, cin: str, permis: str) -> models.Chauffeur:
    return svc.creer_chauffeur(
        schemas.ChauffeurCreate(
            cin=cin,
            nom="Alaoui",
            prenom="Sara",
            telephone="0612345678",
            numero_permis=permis,
            categorie_permis="B",
            date_expiration_permis=date.today() + timedelta(days=365),
        ),
        db,
    )


def test_lecture_chauffeur_sans_jwt_est_refusee(db, monkeypatch):
    """Le contournement historique GET /api/chauffeurs/* doit rester supprimé."""
    monkeypatch.setenv("JWT_SECRET", "secret-de-test-0123456789-0123456789")
    monkeypatch.setenv("SERVICE_API_KEY", "cle-de-service-de-test")
    c = _chauffeur(db, "AB111111", "P-1111")
    main.app.dependency_overrides[database.get_db] = lambda: db

    with TestClient(main.app) as client:
        r = client.get(f"/api/chauffeurs/{c.id_chauffeur}/eligibilite")

    assert r.status_code == 401
    assert r.json()["detail"]["erreur"] == "NON_AUTHENTIFIE"
    main.app.dependency_overrides.clear()


def test_chauffeur_ne_peut_pas_lire_les_routes_administrateur(db, monkeypatch):
    monkeypatch.setenv("JWT_SECRET", "secret-de-test-0123456789-0123456789")
    monkeypatch.setenv("SERVICE_API_KEY", "cle-de-service-de-test")
    c = _chauffeur(db, "AB222222", "P-2222")
    main.app.dependency_overrides[database.get_db] = lambda: db
    token = auth.issue_chauffeur_token(c)

    with TestClient(main.app) as client:
        r = client.get(
            f"/api/chauffeurs/{c.id_chauffeur}/eligibilite",
            headers={"Authorization": f"Bearer {token}"},
        )

    assert r.status_code == 403
    assert r.json()["detail"]["erreur"] == "ACCES_REFUSE"
    main.app.dependency_overrides.clear()


def test_cle_de_service_peut_lire_eligibilite(db, monkeypatch):
    """Entretien et Carburant appellent ce endpoint avec la clé partagée."""
    monkeypatch.setenv("JWT_SECRET", "secret-de-test-0123456789-0123456789")
    monkeypatch.setenv("SERVICE_API_KEY", "cle-de-service-de-test")
    c = _chauffeur(db, "AB333333", "P-3333")
    main.app.dependency_overrides[database.get_db] = lambda: db

    with TestClient(main.app) as client:
        r = client.get(
            f"/api/chauffeurs/{c.id_chauffeur}/eligibilite",
            headers={"X-API-Key": "cle-de-service-de-test"},
        )

    assert r.status_code == 200
    assert r.json()["id_chauffeur"] == c.id_chauffeur
    main.app.dependency_overrides.clear()


def test_espace_chauffeur_ne_donne_que_le_profil_connecte(db, monkeypatch):
    monkeypatch.setenv("JWT_SECRET", "secret-de-test-0123456789-0123456789")
    monkeypatch.setenv("SERVICE_API_KEY", "cle-de-service-de-test")
    c1 = _chauffeur(db, "AB444444", "P-4444")
    _chauffeur(db, "AB555555", "P-5555")
    monkeypatch.setattr(clients, "lister_vehicules", lambda *_args, **_kw: [])
    main.app.dependency_overrides[database.get_db] = lambda: db
    token = auth.issue_chauffeur_token(c1)

    with TestClient(main.app) as client:
        me = client.get("/espace-chauffeur/me", headers={"Authorization": f"Bearer {token}"})
        vehicules = client.get("/espace-chauffeur/vehicules", headers={"Authorization": f"Bearer {token}"})
        sans_token = client.get("/espace-chauffeur/vehicules")

    assert me.status_code == 200
    assert me.json()["id_chauffeur"] == c1.id_chauffeur
    assert vehicules.status_code == 200
    assert sans_token.status_code == 401
    main.app.dependency_overrides.clear()


def test_alias_vehicules_disponibles_exige_un_admin(db, monkeypatch):
    monkeypatch.setenv("JWT_SECRET", "secret-de-test-0123456789-0123456789")
    monkeypatch.setenv("SERVICE_API_KEY", "cle-de-service-de-test")
    monkeypatch.setattr(clients, "lister_vehicules", lambda *_args, **_kw: [])
    main.app.dependency_overrides[database.get_db] = lambda: db

    with TestClient(main.app) as client:
        anonyme = client.get("/api/vehicules-disponibles")
        admin = client.get(
            "/api/vehicules-disponibles",
            headers={"Authorization": f"Bearer {auth.issue_token('admin', auth.ROLE_ADMIN)}"},
        )

    assert anonyme.status_code == 401
    assert admin.status_code == 200
    main.app.dependency_overrides.clear()
