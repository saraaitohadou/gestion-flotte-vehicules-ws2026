import re
from datetime import date, datetime
from typing import Optional

from pydantic import BaseModel, ConfigDict, field_validator

from models import StatutChauffeur

CIN_REGEX = re.compile(r"^[A-Z]{1,2}\d{5,6}$")
TEL_REGEX = re.compile(r"^(?:\+212|0)[67]\d{8}$")
CATEGORIES_PERMIS = {"A", "A1", "B", "C", "D", "EB", "EC", "ED"}


def valider_cin(v: str) -> str:
    v = v.strip().upper()
    if not CIN_REGEX.match(v):
        raise ValueError("CIN invalide : 1 ou 2 lettres + 5 ou 6 chiffres (ex. AB123456)")
    return v


def valider_telephone(v: str) -> str:
    v = v.strip().replace(" ", "")
    if not TEL_REGEX.match(v):
        raise ValueError("Téléphone invalide : 06XXXXXXXX, 07XXXXXXXX ou +2126XXXXXXXX / +2127XXXXXXXX")
    return v


def valider_categorie(v: str) -> str:
    v = v.strip().upper()
    if v not in CATEGORIES_PERMIS:
        raise ValueError(f"Catégorie invalide. Valeurs acceptées : {sorted(CATEGORIES_PERMIS)}")
    return v


def valider_non_vide(v: str) -> str:
    v = v.strip()
    if not v:
        raise ValueError("Ce champ ne peut pas être vide")
    return v


class ChauffeurCreate(BaseModel):
    cin: str
    nom: str
    prenom: str
    telephone: Optional[str] = None
    numero_permis: str
    categorie_permis: str
    date_expiration_permis: date

    @field_validator("cin")
    @classmethod
    def check_cin(cls, v):
        return valider_cin(v)

    @field_validator("telephone")
    @classmethod
    def check_telephone(cls, v):
        return valider_telephone(v) if v is not None else v

    @field_validator("categorie_permis")
    @classmethod
    def check_categorie(cls, v):
        return valider_categorie(v)

    @field_validator("nom", "prenom", "numero_permis")
    @classmethod
    def check_non_vide(cls, v):
        return valider_non_vide(v)


class ChauffeurUpdate(BaseModel):
    cin: Optional[str] = None
    nom: Optional[str] = None
    prenom: Optional[str] = None
    telephone: Optional[str] = None
    numero_permis: Optional[str] = None
    categorie_permis: Optional[str] = None
    date_expiration_permis: Optional[date] = None

    @field_validator("cin")
    @classmethod
    def check_cin(cls, v):
        return valider_cin(v) if v is not None else v

    @field_validator("telephone")
    @classmethod
    def check_telephone(cls, v):
        return valider_telephone(v) if v is not None else v

    @field_validator("categorie_permis")
    @classmethod
    def check_categorie(cls, v):
        return valider_categorie(v) if v is not None else v

    @field_validator("nom", "prenom", "numero_permis")
    @classmethod
    def check_non_vide(cls, v):
        return valider_non_vide(v) if v is not None else v

class ChauffeurStatutUpdate(BaseModel):
    statut: StatutChauffeur

class ChauffeurReactivation(BaseModel):
    date_expiration_permis: Optional[date] = None

class AffectationRequest(BaseModel):
    categorie_vehicule: Optional[str] = None


class CompatibiliteOut(BaseModel):
    id_chauffeur: int
    categorie_permis: str
    categorie_vehicule: str
    compatible: bool


class EligibiliteOut(BaseModel):
    id_chauffeur: int
    statut: StatutChauffeur
    permis_valide: bool
    selectionnable: bool
    affectable: bool
    motifs: list[str]

    
class ChauffeurOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id_chauffeur: int
    cin: str
    nom: str
    prenom: str
    telephone: Optional[str]
    numero_permis: str
    categorie_permis: str
    date_expiration_permis: date
    statut: StatutChauffeur
    created_at: datetime
    updated_at: datetime