from datetime import date
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

import auth
import clients
import models
import regles
import schemas_espace as se
from database import get_db

router = APIRouter(prefix="/espace-chauffeur", tags=["Espace Chauffeur"])


@router.get("/vehicules")
@router.get("/vehicules-disponibles")
def lister_vehicules_service(
    _chauffeur: models.Chauffeur = Depends(auth.get_chauffeur_courant),
):
    """Récupère les véhicules auprès de vehicule-service (chauffeur authentifié)."""
    return clients.lister_vehicules()


def _construire_profil(chauffeur: models.Chauffeur) -> se.ChauffeurMeOut:
    aujourdhui = date.today()
    expire = chauffeur.date_expiration_permis <= aujourdhui
    jours_restants = (chauffeur.date_expiration_permis - aujourdhui).days

    vehicule_info = None
    if chauffeur.vehicule_id:
        v_data = clients.obtenir_vehicule(chauffeur.vehicule_id)
        if v_data:
            vehicule_info = se.VehiculeAssigneOut(
                id=v_data.get("id", chauffeur.vehicule_id),
                immatriculation=v_data.get("immatriculation"),
                marque=v_data.get("marque"),
                modele=v_data.get("modele"),
                annee=v_data.get("annee"),
                kilometrage=v_data.get("kilometrage"),
                typeCarburant=v_data.get("typeCarburant"),
                statut=v_data.get("statut"),
            )
        else:
            vehicule_info = se.VehiculeAssigneOut(id=chauffeur.vehicule_id)

    return se.ChauffeurMeOut(
        id_chauffeur=chauffeur.id_chauffeur,
        cin=chauffeur.cin,
        nom=chauffeur.nom,
        prenom=chauffeur.prenom,
        telephone=chauffeur.telephone,
        numero_permis=chauffeur.numero_permis,
        categorie_permis=chauffeur.categorie_permis,
        date_expiration_permis=chauffeur.date_expiration_permis,
        statut=chauffeur.statut,
        permis_expire=expire,
        jours_avant_expiration=jours_restants,
        vehicule_id=chauffeur.vehicule_id,
        vehicule=vehicule_info,
        created_at=chauffeur.created_at,
        updated_at=chauffeur.updated_at,
    )


# ==========================================================
# 1. Connexion Chauffeur
# ==========================================================

@router.post("/login", response_model=se.ChauffeurTokenResponse)
def login_chauffeur(data: se.ChauffeurLoginRequest, db: Session = Depends(get_db)):
    """Connexion chauffeur avec CIN et numéro de permis. Génère un JWT ou refuse avec 403 si INACTIF."""
    chauffeur = auth.authentifier_chauffeur(db, data.cin, data.numero_permis)
    token = auth.issue_chauffeur_token(chauffeur)
    profil = _construire_profil(chauffeur)

    return se.ChauffeurTokenResponse(
        access_token=token,
        token_type="bearer",
        expires_in=auth.TOKEN_TTL,
        chauffeur=profil,
    )


# ==========================================================
# 2. Profil du chauffeur connecté
# ==========================================================

@router.get("/me", response_model=se.ChauffeurMeOut)
def get_mon_profil(chauffeur: models.Chauffeur = Depends(auth.get_chauffeur_courant)):
    """Retourne le profil du chauffeur connecté, l'état de son permis et son véhicule assigné."""
    return _construire_profil(chauffeur)


# ==========================================================
# 3. Mise à jour du statut et véhicule
# ==========================================================

@router.patch("/me/statut")
def update_mon_statut(
    chauffeur: models.Chauffeur = Depends(auth.get_chauffeur_courant),
):
    """Le chauffeur est en lecture seule sur son statut et ses affectations."""
    raise HTTPException(
        status_code=status.HTTP_403_FORBIDDEN,
        detail={
            "erreur": "ACTION_NON_AUTORISEE",
            "message": "Seul l'administrateur peut modifier votre statut ou vos affectations.",
        },
    )

# ==========================================================
# 4. Déclaration Carburant
# ==========================================================

@router.post("/me/carburant")
def declarer_carburant_chauffeur(
    data: se.DeclarationCarburantIn,
    chauffeur: models.Chauffeur = Depends(auth.get_chauffeur_courant),
):
    """Déclare un plein de carburant pour le véhicule actif du chauffeur (transmet X-Service-Key à carburant-service)."""
    if not chauffeur.vehicule_id:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail={
                "erreur": "AUCUN_VEHICULE_ASSIGNE",
                "message": "Vous n'avez aucun véhicule assigné actuellement pour déclarer du carburant.",
            },
        )

    payload = {
        "vehicule_id": chauffeur.vehicule_id,
        "chauffeur_id": chauffeur.id_chauffeur,
        "date_plein": str(data.date_plein),
        "quantite_litres": data.quantite_litres,
        "prix_litre": data.prix_litre,
        "kilometrage": data.kilometrage,
        "type_carburant": data.type_carburant,
        "station": data.station,
        "plein_complet": data.plein_complet,
    }

    return clients.declarer_carburant(payload)


# ==========================================================
# 5. Entretiens (Consultation & Signalement)
# ==========================================================

@router.get("/me/entretiens")
def consulter_entretiens_chauffeur(
    chauffeur: models.Chauffeur = Depends(auth.get_chauffeur_courant),
):
    """Consulte les entretiens du véhicule assigné au chauffeur (transmet X-Service-Key à entretien-app)."""
    if not chauffeur.vehicule_id:
        return []

    return clients.lister_entretiens_vehicule(chauffeur.vehicule_id)



@router.post("/me/entretiens")
def signaler_entretien_chauffeur(
    data: se.SignalementEntretienIn,
    chauffeur: models.Chauffeur = Depends(auth.get_chauffeur_courant),
):
    """Signale un problème ou demande d'entretien pour le véhicule actif (transmet Bearer JWT et X-Service-Key à entretien-app)."""
    if not chauffeur.vehicule_id:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail={
                "erreur": "AUCUN_VEHICULE_ASSIGNE",
                "message": "Vous n'avez aucun véhicule assigné actuellement pour signaler un entretien.",
            },
        )

    token = auth.issue_chauffeur_token(chauffeur)

    payload = {
        "vehicule_id": chauffeur.vehicule_id,
        "chauffeur_id": chauffeur.id_chauffeur,
        "type": data.type.upper(),
        "categorie": data.categorie.upper(),
        "description": data.description,
        "date_entretien": str(data.date_entretien),
        "kilometrage": data.kilometrage,
        "cout": data.cout,
    }

    return clients.declarer_entretien(payload, bearer_token=token)