from datetime import date, datetime
from typing import Any, Dict, List, Optional
from pydantic import BaseModel, ConfigDict, Field

from models import StatutChauffeur


class ChauffeurLoginRequest(BaseModel):
    cin: str = Field(..., description="CIN du chauffeur (ex: AB123456)")
    numero_permis: str = Field(..., description="Numéro de permis de conduire")


class VehiculeAssigneOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    immatriculation: Optional[str] = None
    marque: Optional[str] = None
    modele: Optional[str] = None
    annee: Optional[int] = None
    kilometrage: Optional[float] = None
    typeCarburant: Optional[str] = None
    statut: Optional[str] = None


class ChauffeurMeOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id_chauffeur: int
    cin: str
    nom: str
    prenom: str
    telephone: Optional[str] = None
    numero_permis: str
    categorie_permis: str
    date_expiration_permis: date
    statut: StatutChauffeur
    permis_expire: bool
    jours_avant_expiration: int
    vehicule_id: Optional[int] = None
    vehicule: Optional[VehiculeAssigneOut] = None
    created_at: Optional[datetime] = None
    updated_at: Optional[datetime] = None


class ChauffeurTokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"
    expires_in: int
    chauffeur: ChauffeurMeOut


class ChauffeurStatutPatchRequest(BaseModel):
    statut: StatutChauffeur
    vehicule_id: Optional[int] = None


class DeclarationCarburantIn(BaseModel):
    date_plein: date
    quantite_litres: float = Field(..., gt=0, description="Quantité en litres ajoutée")
    prix_litre: float = Field(..., gt=0, description="Prix par litre (MAD)")
    kilometrage: int = Field(..., ge=0, description="Kilométrage actuel au compteur")
    type_carburant: str = Field("diesel", description="diesel, essence ou gpl")
    station: Optional[str] = Field(None, max_length=100, description="Nom de la station-service")
    plein_complet: bool = True


class SignalementEntretienIn(BaseModel):
    type: str = Field("CORRECTIF", description="Type d'entretien: CORRECTIF ou PREVENTIF")
    categorie: str = Field("PANNE", description="Catégorie: PANNE, REPARATION, VIDANGE, PNEUS, FREINS, VISITE_TECHNIQUE")
    description: str = Field(..., min_length=3, description="Description du problème ou de l'intervention")
    date_entretien: date = Field(default_factory=date.today, description="Date de l'entretien")
    kilometrage: int = Field(..., ge=0, description="Kilométrage du véhicule lors du signalement")
    cout: float = Field(0.0, ge=0, description="Coût estimé ou engagé")

