"""Appels HTTP vers le service Véhicule (affectation chauffeur ↔ véhicule)."""
import os

import httpx
from fastapi import HTTPException

VEHICULE_URL = os.getenv("VEHICULE_SERVICE_URL", "http://vehicule-service").rstrip("/")
TIMEOUT = float(os.getenv("SERVICE_TIMEOUT", "3"))


def _erreur(http: int, code: str, message: str):
    raise HTTPException(http, detail={"erreur": code, "message": message})


def _appeler(methode: str, chemin: str, **kw) -> httpx.Response:
    try:
        return httpx.request(methode, VEHICULE_URL + chemin, timeout=TIMEOUT, **kw)
    except httpx.HTTPError:
        _erreur(503, "VEHICULE_SERVICE_INDISPONIBLE", "Service Véhicule injoignable.")


def verifier_disponibilite(vehicule_id: int) -> None:
    """GET /vehicules/{id}/disponibilite : 404, 409 ou 503 si le véhicule ne peut pas être affecté."""
    r = _appeler("GET", f"/vehicules/{vehicule_id}/disponibilite")
    if r.status_code == 404:
        _erreur(404, "VEHICULE_INTROUVABLE", f"Véhicule {vehicule_id} introuvable.")
    if r.status_code != 200:
        _erreur(503, "VEHICULE_SERVICE_INDISPONIBLE", f"Service Véhicule a répondu {r.status_code}.")
    if not r.json().get("disponible"):
        _erreur(409, "VEHICULE_INDISPONIBLE", f"Le véhicule {vehicule_id} n'est pas disponible.")


def changer_statut_vehicule(vehicule_id: int, statut: str) -> None:
    """PATCH /vehicules/{id}/statut (EN_MISSION au début, DISPONIBLE à la clôture)."""
    r = _appeler("PATCH", f"/vehicules/{vehicule_id}/statut", json={"statut": statut})
    if r.status_code == 200:
        return
    if r.status_code == 404:
        _erreur(404, "VEHICULE_INTROUVABLE", f"Véhicule {vehicule_id} introuvable.")
    if r.status_code in (409, 422):
        msg = (r.json() or {}).get("erreur", "Changement de statut refusé par le service Véhicule.")
        _erreur(r.status_code, "VEHICULE_STATUT_REFUSE", msg)
    _erreur(503, "VEHICULE_SERVICE_INDISPONIBLE", f"Service Véhicule a répondu {r.status_code}.")