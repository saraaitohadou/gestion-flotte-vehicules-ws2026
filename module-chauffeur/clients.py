"""Appels HTTP vers les services Véhicule, Carburant et Entretien."""
import os
from typing import Any, Dict, List, Optional

import httpx
from fastapi import HTTPException, status

import auth

VEHICULE_URL = os.getenv("VEHICULE_SERVICE_URL", "http://vehicule-service").rstrip("/")
CARBURANT_URL = os.getenv("CARBURANT_SERVICE_URL", "http://carburant-service:8000").rstrip("/")
ENTRETIEN_URL = os.getenv("ENTRETIEN_SERVICE_URL", "http://entretien-app:8004").rstrip("/")
TIMEOUT = float(os.getenv("SERVICE_TIMEOUT", "4"))

def _auth_headers(bearer_token: Optional[str] = None) -> dict:
    headers = _service_headers()
    if bearer_token:
        val = bearer_token if bearer_token.lower().startswith("bearer ") else f"Bearer {bearer_token}"
        headers["Authorization"] = val
    return headers


def _service_headers() -> dict:
    key = os.getenv("SERVICE_API_KEY", "")
    return {
        "X-Service-Key": key,
        "X-API-Key": key,
        "Accept": "application/json",
    }


def _erreur(http: int, code: str, message: Any):
    raise HTTPException(http, detail={"erreur": code, "message": message})


def _appeler(methode: str, base_url: str, chemin: str, **kw) -> httpx.Response:
    url = f"{base_url}{chemin}"
    try:
        return httpx.request(methode, url, timeout=TIMEOUT, **kw)
    except httpx.HTTPError:
        nom_service = "Véhicule" if base_url == VEHICULE_URL else ("Carburant" if base_url == CARBURANT_URL else "Entretien")
        _erreur(status.HTTP_503_SERVICE_UNAVAILABLE, f"{nom_service.upper()}_SERVICE_INDISPONIBLE", f"Service {nom_service} injoignable ({url}).")


# ==========================================
# Service Véhicule
# ==========================================
def verifier_disponibilite(vehicule_id: int, bearer_token: Optional[str] = None) -> None:
    """GET /vehicules/{id}/disponibilite : 404, 409 ou 503 si le véhicule ne peut pas être affecté."""
    r = _appeler("GET", VEHICULE_URL, f"/vehicules/{vehicule_id}/disponibilite", headers=_auth_headers(bearer_token))
    if r.status_code == 404:
        _erreur(404, "VEHICULE_INTROUVABLE", f"Véhicule {vehicule_id} introuvable.")
    if r.status_code != 200:
        _erreur(503, "VEHICULE_SERVICE_INDISPONIBLE", f"Service Véhicule a répondu {r.status_code}.")
    if not r.json().get("disponible"):
        _erreur(409, "VEHICULE_INDISPONIBLE", f"Le véhicule {vehicule_id} n'est pas disponible.")


def changer_statut_vehicule(vehicule_id: int, statut: str, bearer_token: Optional[str] = None) -> None:
    """PATCH /vehicules/{id}/statut (EN_MISSION au début, DISPONIBLE à la clôture)."""
    r = _appeler(
        "PATCH",
        VEHICULE_URL,
        f"/vehicules/{vehicule_id}/statut",
        json={"statut": statut},
        headers=_auth_headers(bearer_token),
    )
    if r.status_code == 200:
        return
    if r.status_code == 404:
        _erreur(404, "VEHICULE_INTROUVABLE", f"Véhicule {vehicule_id} introuvable.")
    if r.status_code in (409, 422):
        msg = (r.json() or {}).get("erreur", "Changement de statut refusé par le service Véhicule.")
        _erreur(r.status_code, "VEHICULE_STATUT_REFUSE", msg)
    _erreur(503, "VEHICULE_SERVICE_INDISPONIBLE", f"Service Véhicule a répondu {r.status_code}.")


def obtenir_vehicule(vehicule_id: int, bearer_token: Optional[str] = None) -> Optional[Dict[str, Any]]:
    """GET /vehicules/{id} : récupère les détails d'un véhicule."""
    try:
        r = _appeler("GET", VEHICULE_URL, f"/vehicules/{vehicule_id}", headers=_auth_headers(bearer_token))
        if r.status_code == 200:
            return r.json()
        return None
    except HTTPException:
        return None


def lister_vehicules(bearer_token: Optional[str] = None) -> List[Dict[str, Any]]:
    """GET /vehicules : récupère la liste de tous les véhicules."""
    try:
        r = _appeler("GET", VEHICULE_URL, "/vehicules", headers=_auth_headers(bearer_token))
        if r.status_code == 200:
            data = r.json()
            if isinstance(data, list):
                return data
            if isinstance(data, dict) and "data" in data:
                return data["data"]
        return []
    except Exception:
        return []


def lister_vehicules_disponibles(bearer_token: Optional[str] = None) -> List[Dict[str, Any]]:
    """Retourne les véhicules disponibles (ou enregistrés) auprès de vehicule-service."""
    vehicules = lister_vehicules(bearer_token=bearer_token)
    disponibles = [v for v in vehicules if str(v.get("statut", "")).upper() == "DISPONIBLE"]
    return disponibles if disponibles else vehicules
# ==========================================
# Service Carburant
# ==========================================

def declarer_carburant(donnees: dict) -> Dict[str, Any]:
    """POST /carburants : transmet la déclaration de carburant avec X-Service-Key."""
    r = _appeler("POST", CARBURANT_URL, "/carburants", json=donnees, headers=_service_headers())
    if r.status_code in (200, 201):
        return r.json()
    detail = None
    try:
        detail = r.json()
    except Exception:
        detail = r.text
    _erreur(r.status_code, "ERREUR_CARBURANT", detail)


# ==========================================
# Service Entretien
# ==========================================

def _entretien_headers() -> dict:
    """Entretien exige un JWT : on émet un token de service court avec le secret partagé.

    La clé X-Service-Key/X-API-Key est conservée pour les autres services, mais
    les routes /api d'Entretien sont protégées par leur middleware JWT.
    """
    headers = _service_headers()
    token = auth.issue_token(sub="service-chauffeur", role=auth.ROLE_ADMIN, ttl=300)
    headers["Authorization"] = f"Bearer {token}"
    return headers


def lister_entretiens_vehicule(vehicule_id: int) -> List[Dict[str, Any]]:
    """GET /api/vehicules/{id}/entretiens ou GET /api/entretiens?vehicule_id={id}."""
    headers = _entretien_headers()
    # Tentative 1 : /api/vehicules/{id}/entretiens
    r = _appeler("GET", ENTRETIEN_URL, f"/api/vehicules/{vehicule_id}/entretiens", headers=headers)
    if r.status_code == 200:
        data = r.json()
        if isinstance(data, dict) and "data" in data:
            return data["data"]
        if isinstance(data, list):
            return data

    # Tentative 2 : /api/entretiens?vehicule_id={id}
    r2 = _appeler("GET", ENTRETIEN_URL, f"/api/entretiens?vehicule_id={vehicule_id}", headers=headers)
    if r2.status_code == 200:
        data = r2.json()
        if isinstance(data, dict) and "data" in data:
            return data["data"]
        if isinstance(data, list):
            return data
        return []

    return []


def declarer_entretien(donnees: dict, bearer_token: Optional[str] = None) -> Dict[str, Any]:
    """POST /api/declarations-panne avec le Bearer JWT du chauffeur et X-Service-Key.

    L'identite du chauffeur est prise par Entretien dans le JWT (jamais dans le corps) :
    c'est la route declaree pour un signalement de panne. La correction precedente sur
    /api/vehicules/{id}/entretiens (JWT de service) est conservee plus haut dans ce fichier.
    """
    r = _appeler("POST", ENTRETIEN_URL, "/api/declarations-panne", json=donnees, headers=_auth_headers(bearer_token))
    if r.status_code in (200, 201):
        data = r.json()
        if isinstance(data, dict) and "data" in data:
            return data["data"]
        return data
    detail = None
    try:
        detail = r.json()
    except Exception:
        detail = r.text
    _erreur(r.status_code, "ERREUR_ENTRETIEN", detail)