"""Appels HTTP vers les services Vehicule et Chauffeur."""
import os
from typing import Optional

import httpx
from fastapi import HTTPException

import auth

VEHICULE_URL = os.getenv("VEHICULE_SERVICE_URL", "http://vehicule-service")
CHAUFFEUR_URL = os.getenv("CHAUFFEUR_SERVICE_URL", "http://chauffeur-service:8000")
ENTRETIEN_URL = os.getenv("ENTRETIEN_SERVICE_URL", "http://entretien-app:8004").rstrip("/")
TIMEOUT = float(os.getenv("SERVICE_TIMEOUT", "3"))

# Routes reelles des autres modules (a ajuster si l'equipe les change)
CHEMIN_VEHICULE = "/vehicules/{id}"
CHEMIN_VEHICULES = "/vehicules"
CHEMIN_CHAUFFEUR = "/api/chauffeurs/{id}"
CHEMIN_CHAUFFEURS = "/api/chauffeurs"


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


def lister_vehicules() -> list[dict[str, int | str]]:
    try:
        reponse = httpx.get(
            VEHICULE_URL + CHEMIN_VEHICULES,
            headers=_en_tetes(),
            timeout=TIMEOUT,
        )
    except httpx.HTTPError:
        raise HTTPException(503, "Service Vehicule indisponible")
    if reponse.status_code >= 400:
        raise HTTPException(503, f"Service Vehicule a repondu {reponse.status_code}")
    try:
        vehicules = reponse.json()
        if not isinstance(vehicules, list) or any(
            not isinstance(item, dict)
            or not isinstance(item.get("id"), int)
            or not isinstance(item.get("immatriculation"), str)
            for item in vehicules
        ):
            raise ValueError
        return [
            {"id": item["id"], "immatriculation": item["immatriculation"]}
            for item in vehicules
        ]
    except (TypeError, ValueError):
        raise HTTPException(503, "Reponse du service Vehicule invalide")


def verifier_chauffeur(chauffeur_id: int) -> None:
    _verifier(CHAUFFEUR_URL, CHEMIN_CHAUFFEUR, chauffeur_id, "Chauffeur")


def lister_chauffeurs() -> list[dict[str, int | str]]:
    try:
        reponse = httpx.get(
            CHAUFFEUR_URL + CHEMIN_CHAUFFEURS,
            params={"limit": 200},
            headers=_en_tetes(),
            timeout=TIMEOUT,
        )
    except httpx.HTTPError:
        raise HTTPException(503, "Service Chauffeur indisponible")
    if reponse.status_code >= 400:
        raise HTTPException(503, f"Service Chauffeur a repondu {reponse.status_code}")
    try:
        chauffeurs = reponse.json()
        if not isinstance(chauffeurs, list) or any(
            not isinstance(item, dict)
            or not isinstance(item.get("id_chauffeur"), int)
            or not isinstance(item.get("nom"), str)
            or not isinstance(item.get("prenom"), str)
            for item in chauffeurs
        ):
            raise ValueError
        return [
            {
                "id_chauffeur": item["id_chauffeur"],
                "nom": item["nom"],
                "prenom": item["prenom"],
            }
            for item in chauffeurs
        ]
    except (TypeError, ValueError):
        raise HTTPException(503, "Reponse du service Chauffeur invalide")


def lister_entretiens_vehicule(vehicule_id: int) -> list[dict]:
    """Recupere l'historique d'entretien d'un vehicule."""
    token = auth.issue_token_admin(sub="service-carburant", ttl=300)
    headers = {
        **_en_tetes(),
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
    }
    try:
        reponse = httpx.get(
            f"{ENTRETIEN_URL}/api/vehicules/{vehicule_id}/entretiens",
            headers=headers,
            timeout=TIMEOUT,
        )
    except httpx.HTTPError:
        raise HTTPException(503, "Service Entretien indisponible")
    if reponse.status_code >= 400:
        raise HTTPException(503, f"Service Entretien a repondu {reponse.status_code}")

    try:
        payload = reponse.json()
        entretiens = payload.get("data") if isinstance(payload, dict) else payload
        if not isinstance(entretiens, list) or any(
            not isinstance(item, dict)
            or not isinstance(item.get("date_entretien"), str)
            or not isinstance(item.get("type"), str)
            for item in entretiens
        ):
            raise ValueError
    except (TypeError, ValueError):
        raise HTTPException(503, "Reponse du service Entretien invalide")
    return entretiens


def dernier_entretien_vehicule(vehicule_id: int) -> Optional[dict[str, str]]:
    """Retourne la derniere intervention terminee pour un vehicule."""
    entretiens = lister_entretiens_vehicule(vehicule_id)
    termines = [
        item for item in entretiens
        if item.get("statut") == "TERMINE"
    ]
    if not termines:
        return None

    dernier = max(
        termines,
        key=lambda item: (item["date_entretien"], item.get("id", 0)),
    )
    return {
        "date_entretien": dernier["date_entretien"],
        "type": dernier["type"],
        "categorie": dernier.get("categorie") or "",
    }


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