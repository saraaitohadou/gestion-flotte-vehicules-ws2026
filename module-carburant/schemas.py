from datetime import date
from enum import Enum
from typing import Optional

from pydantic import BaseModel, ConfigDict, Field


class TypeCarburant(str, Enum):
    diesel = "diesel"
    essence = "essence"
    gpl = "gpl"


# ---------- Carburant ----------

class CarburantCreate(BaseModel):
    vehicule_id: int
    chauffeur_id: Optional[int] = None
    date_plein: date
    quantite_litres: float = Field(gt=0, description="Litres ajoutes")
    prix_litre: float = Field(gt=0, description="Prix par litre (MAD)")
    kilometrage: int = Field(ge=0, description="Compteur au moment du plein")
    type_carburant: TypeCarburant
    station: Optional[str] = None
    plein_complet: bool = True


class CarburantUpdate(BaseModel):
    vehicule_id: Optional[int] = None
    chauffeur_id: Optional[int] = None
    date_plein: Optional[date] = None
    quantite_litres: Optional[float] = Field(default=None, gt=0)
    prix_litre: Optional[float] = Field(default=None, gt=0)
    kilometrage: Optional[int] = Field(default=None, ge=0)
    type_carburant: Optional[TypeCarburant] = None
    station: Optional[str] = None
    plein_complet: Optional[bool] = None


class CarburantOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    vehicule_id: int
    chauffeur_id: Optional[int]
    date_plein: date
    quantite_litres: float
    prix_litre: float
    montant_total: float
    kilometrage: int
    type_carburant: str
    station: Optional[str]
    plein_complet: bool
    consommation_l_100km: Optional[float] = None
    anomalie: bool = False
    message_anomalie: Optional[str] = None


class StatsCarburant(BaseModel):
    vehicule_id: int
    nombre_pleins: int
    total_litres: float
    cout_total: float
    distance_km: int
    consommation_l_100km: Optional[float] = None
    cout_au_km: Optional[float] = None


# ---------- Vehicule / Chauffeur (minimal, pour tester le module) ----------

class VehiculeCreate(BaseModel):
    immatriculation: str
    marque: str
    modele: str


class VehiculeOut(VehiculeCreate):
    model_config = ConfigDict(from_attributes=True)

    id: int


class ChauffeurCreate(BaseModel):
    nom: str
    prenom: str
    telephone: Optional[str] = None


class ChauffeurOut(ChauffeurCreate):
    model_config = ConfigDict(from_attributes=True)

    id: int
