from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

import models
import schemas
from database import get_db

router = APIRouter(prefix="/api/chauffeurs", tags=["Chauffeurs"])

def verifier_unicite(db: Session, cin: str | None, numero_permis: str | None,
                     exclure_id: int | None = None):
    """Lève une erreur 409 si le CIN ou le numéro de permis existe déjà."""
    if cin is not None:
        q = db.query(models.Chauffeur).filter(models.Chauffeur.cin == cin)
        if exclure_id is not None:
            q = q.filter(models.Chauffeur.id_chauffeur != exclure_id)
        if q.first():
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail={"erreur": "CIN_DEJA_UTILISE",
                        "message": f"Le CIN {cin} existe déjà."},
            )

    if numero_permis is not None:
        q = db.query(models.Chauffeur).filter(models.Chauffeur.numero_permis == numero_permis)
        if exclure_id is not None:
            q = q.filter(models.Chauffeur.id_chauffeur != exclure_id)
        if q.first():
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail={"erreur": "PERMIS_DEJA_UTILISE",
                        "message": f"Le numéro de permis {numero_permis} existe déjà."},
            )


@router.post("", response_model=schemas.ChauffeurOut, status_code=status.HTTP_201_CREATED)
def creer_chauffeur(data: schemas.ChauffeurCreate, db: Session = Depends(get_db)):
    verifier_unicite(db, data.cin, data.numero_permis)

    chauffeur = models.Chauffeur(**data.model_dump())
    db.add(chauffeur)
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={"erreur": "DOUBLON",
                    "message": "Le CIN ou le numéro de permis existe déjà."},
        )
    db.refresh(chauffeur)
    return chauffeur


@router.put("/{id_chauffeur}", response_model=schemas.ChauffeurOut)
def modifier_chauffeur(id_chauffeur: int, data: schemas.ChauffeurUpdate,
                       db: Session = Depends(get_db)):
    chauffeur = (
        db.query(models.Chauffeur)
        .filter(models.Chauffeur.id_chauffeur == id_chauffeur,
                models.Chauffeur.statut != models.StatutChauffeur.INACTIF)
        .first()
    )
    if chauffeur is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={"erreur": "CHAUFFEUR_INTROUVABLE",
                    "message": f"Aucun chauffeur actif avec l'id {id_chauffeur}."},
        )

    changements = data.model_dump(exclude_unset=True)
    verifier_unicite(db, changements.get("cin"), changements.get("numero_permis"),
                     exclure_id=id_chauffeur)

    for champ, valeur in changements.items():
        setattr(chauffeur, champ, valeur)

    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={"erreur": "DOUBLON",
                    "message": "Le CIN ou le numéro de permis existe déjà."},
        )
    db.refresh(chauffeur)
    return chauffeur