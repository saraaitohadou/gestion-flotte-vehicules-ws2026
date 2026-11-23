"""Appels HTTP vers les services Vehicule et Chauffeur."""
import os
from typing import Optional

import httpx
from fastapi import HTTPException

VEHICULE_URL = os.getenv("VEHICULE_SERVICE_URL", "http://vehicule-service")
CHAUFFEUR_URL = os.getenv("CHAUFFEUR_SERVICE_URL", "http://chauffeur-service:8000")
TIMEOUT = float(os.getenv("SERVICE_TIMEOUT", "3"))

# Routes reelles des autres modules (a ajuster si l'equipe les change)
CHEMIN_VEHICULE = "/vehicules/{id}"
CHEMIN_CHAUFFEUR = "/api/chauffeurs/{id}"


def _en_tetes() -> dict:
    """Cle de service partagee: les services Vehicule et Chauffeur l'acceptent
    dans l'en-tete X-API-Key (variable SERVICE_API_KEY du docker-compose)."""
    cle = os.getenv("SERVICE_API_KEY", "")
    return {"X-API-Key": cle} if cle else {}


def _verifier(base: str, chemin: str, ident: int, nom: str) -> None:
    url = base + chemin.format(id=ident)
    try:
        reponse = httpx.get(url, headers=_en_tetes(), timeout=TIMEOUT)
    except httpx.HTTPError:
        raise HTTPException(503, f"Service {nom} indisponible")
    if reponse.status_code == 404:
        raise HTTPException(404, f"{nom} {ident} introuvable")
    if reponse.status_code >= 400:
        raise HTTPException(503, f"Service {nom} a repondu {reponse.status_code}")


def verifier_vehicule(vehicule_id: int) -> None:
    _verifier(VEHICULE_URL, CHEMIN_VEHICULE, vehicule_id, "Vehicule")


def verifier_chauffeur(chauffeur_id: int) -> None:
    _verifier(CHAUFFEUR_URL, CHEMIN_CHAUFFEUR, chauffeur_id, "Chauffeur")


def obtenir_vehicule_affecte(chauffeur_id: int) -> Optional[int]:
    """Retourne vehicule_id affecte au chauffeur via le service Chauffeur."""
    url = CHAUFFEUR_URL + CHEMIN_CHAUFFEUR.format(id=chauffeur_id)
    try:
        reponse = httpx.get(url, headers=_en_tetes(), timeout=TIMEOUT)
    except httpx.HTTPError:
        raise HTTPException(503, "Service Chauffeur indisponible")
    if reponse.status_code == 404:
        raise HTTPException(404, f"Chauffeur {chauffeur_id} introuvable")
    if reponse.status_code >= 400:
        raise HTTPException(503, f"Service Chauffeur a repondu {reponse.status_code}")
    try:
        payload = reponse.json()
        vehicule_id = payload.get("vehicule_id")
        return int(vehicule_id) if vehicule_id is not None else None
    except (TypeError, ValueError):
        raise HTTPException(503, "Reponse du service Chauffeur invalide")