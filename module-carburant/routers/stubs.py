"""Routes minimales pour Vehicule et Chauffeur.

Temporaire: elles permettent de tester le module carburant seul. Quand les
modules Vehicule et Chauffeur de l'equipe seront prets, supprimer ce fichier
et son inclusion dans main.py.
"""
from typing import List

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

import models
import schemas
from database import get_db

router = APIRouter(tags=["(temporaire) Vehicule & Chauffeur"])


@router.post("/vehicules", response_model=schemas.VehiculeOut, status_code=201)
def creer_vehicule(data: schemas.VehiculeCreate, db: Session = Depends(get_db)):
    if db.query(models.Vehicule).filter_by(immatriculation=data.immatriculation).first():
        raise HTTPException(409, "Immatriculation deja utilisee")
    obj = models.Vehicule(**data.model_dump())
    db.add(obj)
    db.commit()
    db.refresh(obj)
    return obj


@router.get("/vehicules", response_model=List[schemas.VehiculeOut])
def lister_vehicules(db: Session = Depends(get_db)):
    return db.query(models.Vehicule).all()


@router.post("/chauffeurs", response_model=schemas.ChauffeurOut, status_code=201)
def creer_chauffeur(data: schemas.ChauffeurCreate, db: Session = Depends(get_db)):
    obj = models.Chauffeur(**data.model_dump())
    db.add(obj)
    db.commit()
    db.refresh(obj)
    return obj


@router.get("/chauffeurs", response_model=List[schemas.ChauffeurOut])
def lister_chauffeurs(db: Session = Depends(get_db)):
    return db.query(models.Chauffeur).all()
