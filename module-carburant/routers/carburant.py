from datetime import date
from typing import Annotated, List, Optional

from fastapi import APIRouter, Body, Depends, HTTPException, Query, Response, status
from sqlalchemy.orm import Session
import clients  
import models
import schemas
from anomalies import recalculer_vehicule
from database import get_db
from auth import require_auth, ROLE_ADMIN, ROLE_GESTIONNAIRE, ROLE_CHAUFFEUR

router = APIRouter(prefix="/carburants", tags=["Carburant"])


# ---------- Verifications ----------

def verifier_references(vehicule_id: int, chauffeur_id: Optional[int]):
    clients.verifier_vehicule(vehicule_id)
    if chauffeur_id is not None:
        clients.verifier_chauffeur(chauffeur_id)


def verifier_affectation(vehicule_id: int, chauffeur_id: Optional[int]):
    """Verifie que le chauffeur fourni est affecte au meme vehicule."""
    if chauffeur_id is None:
        return
    vehicule_affecte = clients.obtenir_vehicule_affecte(chauffeur_id)
    if vehicule_affecte is None:
        raise HTTPException(400, "Ce chauffeur n'est pas affecté à ce véhicule")
    if vehicule_affecte != vehicule_id:
        raise HTTPException(400, "Ce chauffeur n'est pas affecté à ce véhicule")

def verifier_kilometrage(
    db: Session,
    vehicule_id: int,
    date_plein: date,
    kilometrage: int,
    exclude_id: Optional[int] = None,
):
    """RG-01: le kilometrage doit etre strictement croissant dans le temps pour un meme vehicule (400 sinon)."""
    base = db.query(models.Carburant).filter(models.Carburant.vehicule_id == vehicule_id)
    if exclude_id is not None:
        base = base.filter(models.Carburant.id != exclude_id)

    precedent = (
        base.filter(models.Carburant.date_plein < date_plein)
        .order_by(models.Carburant.date_plein.desc(), models.Carburant.kilometrage.desc())
        .first()
    )
    suivant = (
        base.filter(models.Carburant.date_plein > date_plein)
        .order_by(models.Carburant.date_plein.asc(), models.Carburant.kilometrage.asc())
        .first()
    )
    if precedent and kilometrage <= precedent.kilometrage:
        raise HTTPException(
            400,
            f"Kilometrage incoherent: {kilometrage} km est inferieur ou egal au plein du "
            f"{precedent.date_plein} ({precedent.kilometrage} km)",
        )
    if suivant and kilometrage >= suivant.kilometrage:
        raise HTTPException(
            400,
            f"Kilometrage incoherent: {kilometrage} km est superieur ou egal au plein du "
            f"{suivant.date_plein} ({suivant.kilometrage} km)",
        )

    meme_date = base.filter(models.Carburant.date_plein == date_plein)
    if exclude_id is not None:
        precedent_meme_date = (
            meme_date.filter(models.Carburant.id < exclude_id)
            .order_by(models.Carburant.id.desc())
            .first()
        )
        suivant_meme_date = (
            meme_date.filter(models.Carburant.id > exclude_id)
            .order_by(models.Carburant.id.asc())
            .first()
        )
    else:
        precedent_meme_date = meme_date.order_by(models.Carburant.id.desc()).first()
        suivant_meme_date = None

    if precedent_meme_date and kilometrage <= precedent_meme_date.kilometrage:
        raise HTTPException(
            400,
            f"Kilometrage incoherent: {kilometrage} km est inferieur ou egal au plein "
            f"precedent du meme jour ({precedent_meme_date.kilometrage} km)",
        )
    if suivant_meme_date and kilometrage >= suivant_meme_date.kilometrage:
        raise HTTPException(
            400,
            f"Kilometrage incoherent: {kilometrage} km est superieur ou egal au plein "
            f"suivant du meme jour ({suivant_meme_date.kilometrage} km)",
        )


def get_or_404(db: Session, carburant_id: int) -> models.Carburant:
    obj = db.get(models.Carburant, carburant_id)
    if not obj:
        raise HTTPException(404, "Plein introuvable")
    return obj


# ---------- Statistiques ----------
# (declare avant /{carburant_id} pour eviter toute ambiguite de routage)

@router.get("/stats/vehicules/{vehicule_id}", response_model=schemas.StatsCarburant)
def stats_vehicule(
    vehicule_id: int,
    date_debut: Optional[date] = None,
    date_fin: Optional[date] = None,
    db: Session = Depends(get_db),
    auth: dict = Depends(require_auth),
):
    """Cout total et consommation moyenne (L/100 km) d'un vehicule.

    La consommation exclut le premier plein de la periode: ces litres servent
    a rouler avant le premier releve, pas apres.
    """
    clients.verifier_vehicule(vehicule_id)

    q = db.query(models.Carburant).filter(models.Carburant.vehicule_id == vehicule_id)
    if not isinstance(auth, dict):
        auth = {"role": ROLE_ADMIN}
    if auth.get("role") == ROLE_CHAUFFEUR:
        id_chauffeur = auth.get("id_chauffeur")
        if id_chauffeur is None:
            try:
                id_chauffeur = int(auth.get("sub"))
            except (TypeError, ValueError):
                raise HTTPException(403, "Identifiant chauffeur absent du jeton")
        q = q.filter(models.Carburant.chauffeur_id == id_chauffeur)
    if date_debut:
        q = q.filter(models.Carburant.date_plein >= date_debut)
    if date_fin:
        q = q.filter(models.Carburant.date_plein <= date_fin)
    pleins = q.order_by(models.Carburant.date_plein, models.Carburant.kilometrage).all()

    total_litres = sum(p.quantite_litres for p in pleins)
    cout_total = sum(p.montant_total for p in pleins)
    distance = pleins[-1].kilometrage - pleins[0].kilometrage if len(pleins) >= 2 else 0

    conso = cout_km = None
    if distance > 0:
        litres_utiles = total_litres - pleins[0].quantite_litres
        cout_utile = cout_total - pleins[0].montant_total
        conso = round(litres_utiles / distance * 100, 2)
        cout_km = round(cout_utile / distance, 3)

    return schemas.StatsCarburant(
        vehicule_id=vehicule_id,
        nombre_pleins=len(pleins),
        total_litres=round(total_litres, 2),
        cout_total=round(cout_total, 2),
        distance_km=distance,
        consommation_l_100km=conso,
        cout_au_km=cout_km,
    )


# ---------- Alertes de surconsommation ----------

@router.get("/anomalies", response_model=List[schemas.CarburantOut])
def lister_anomalies(
    vehicule_id: Optional[int] = None,
    date_debut: Optional[date] = None,
    date_fin: Optional[date] = None,
    db: Session = Depends(get_db),
    auth: dict = Depends(require_auth),
):
    """Pleins marques en surconsommation (alerte pour l'administrateur)."""
    q = db.query(models.Carburant).filter(models.Carburant.anomalie.is_(True))
    if not isinstance(auth, dict):
        auth = {"role": ROLE_ADMIN}
    if auth.get("role") == ROLE_CHAUFFEUR:
        id_chauffeur = auth.get("id_chauffeur")
        if id_chauffeur is None:
            try:
                id_chauffeur = int(auth.get("sub"))
            except (TypeError, ValueError):
                raise HTTPException(403, "Identifiant chauffeur absent du jeton")
        q = q.filter(models.Carburant.chauffeur_id == id_chauffeur)
    if vehicule_id is not None:
        q = q.filter(models.Carburant.vehicule_id == vehicule_id)
    if date_debut:
        q = q.filter(models.Carburant.date_plein >= date_debut)
    if date_fin:
        q = q.filter(models.Carburant.date_plein <= date_fin)
    return q.order_by(models.Carburant.date_plein.desc(), models.Carburant.id.desc()).all()


# ---------- CRUD ----------

@router.post("", response_model=schemas.CarburantOut, status_code=status.HTTP_201_CREATED)
def creer_plein(
    data: Annotated[dict, Body(embed=False)],
    db: Session = Depends(get_db),
    auth: dict = Depends(require_auth),
):
    if not isinstance(auth, dict):
        auth = {"role": ROLE_ADMIN}
    role = auth.get("role")
    if role == ROLE_CHAUFFEUR:
        raise HTTPException(403, "Lecture seule pour un chauffeur")
    if role not in (ROLE_ADMIN, ROLE_GESTIONNAIRE, "SERVICE"):
        raise HTTPException(403, "Acces refuse")
    if isinstance(data, schemas.CarburantCreate):
        payload = data
    else:
        try:
            payload = schemas.CarburantCreate.model_validate(data)
        except Exception as exc:
            raise HTTPException(422, exc.errors())
    data = payload
    verifier_references(data.vehicule_id, data.chauffeur_id)
    verifier_affectation(data.vehicule_id, data.chauffeur_id)
    verifier_kilometrage(db, data.vehicule_id, data.date_plein, data.kilometrage)

    valeurs = data.model_dump()
    valeurs["type_carburant"] = data.type_carburant.value
    obj = models.Carburant(
        **valeurs,
        montant_total=round(data.quantite_litres * data.prix_litre, 2),
    )
    db.add(obj)
    db.commit()
    recalculer_vehicule(db, obj.vehicule_id)  # consommation + alerte
    db.refresh(obj)
    return obj


@router.get("", response_model=List[schemas.CarburantOut])
def lister_pleins(
    vehicule_id: Optional[int] = None,
    chauffeur_id: Optional[int] = None,
    type_carburant: Optional[schemas.TypeCarburant] = None,
    date_debut: Optional[date] = None,
    date_fin: Optional[date] = None,
    skip: int = Query(0, ge=0),
    limit: int = Query(50, ge=1, le=500),
    db: Session = Depends(get_db),
    auth: dict = Depends(require_auth),
):
    if not isinstance(auth, dict):
        auth = {"role": ROLE_ADMIN}
    role = auth.get("role")
    if role not in (ROLE_ADMIN, ROLE_GESTIONNAIRE, "SERVICE", ROLE_CHAUFFEUR):
        raise HTTPException(403, "Acces refuse")
    q = db.query(models.Carburant)
    if role == ROLE_CHAUFFEUR:
        id_chauffeur = auth.get("id_chauffeur")
        if id_chauffeur is None:
            try:
                id_chauffeur = int(auth.get("sub"))
            except (TypeError, ValueError):
                raise HTTPException(403, "Identifiant chauffeur absent du jeton")
        chauffeur_id = id_chauffeur
    if vehicule_id is not None:
        q = q.filter(models.Carburant.vehicule_id == vehicule_id)
    if chauffeur_id is not None:
        q = q.filter(models.Carburant.chauffeur_id == chauffeur_id)
    if type_carburant is not None:
        q = q.filter(models.Carburant.type_carburant == type_carburant.value)
    if date_debut:
        q = q.filter(models.Carburant.date_plein >= date_debut)
    if date_fin:
        q = q.filter(models.Carburant.date_plein <= date_fin)
    return (
        q.order_by(models.Carburant.date_plein.desc(), models.Carburant.id.desc())
        .offset(skip)
        .limit(limit)
        .all()
    )


@router.get("/{carburant_id}", response_model=schemas.CarburantOut)
def lire_plein(
    carburant_id: int,
    db: Session = Depends(get_db),
    auth: dict = Depends(require_auth),
):
    obj = get_or_404(db, carburant_id)
    if not isinstance(auth, dict):
        auth = {"role": ROLE_ADMIN}
    if auth.get("role") == ROLE_CHAUFFEUR:
        id_chauffeur = auth.get("id_chauffeur")
        if id_chauffeur is None:
            try:
                id_chauffeur = int(auth.get("sub"))
            except (TypeError, ValueError):
                raise HTTPException(403, "Identifiant chauffeur absent du jeton")
        if obj.chauffeur_id != id_chauffeur:
            raise HTTPException(404, "Plein introuvable")
    return obj


@router.patch("/{carburant_id}", response_model=schemas.CarburantOut)
def modifier_plein(
    carburant_id: int,
    data: Annotated[dict, Body(embed=False)],
    db: Session = Depends(get_db),
    auth: dict = Depends(require_auth),
):
    if not isinstance(auth, dict):
        auth = {"role": ROLE_ADMIN}
    role = auth.get("role")
    if role == ROLE_CHAUFFEUR:
        raise HTTPException(403, "Lecture seule pour un chauffeur")
    if role not in (ROLE_ADMIN, ROLE_GESTIONNAIRE, "SERVICE"):
        raise HTTPException(403, "Acces refuse")
    if isinstance(data, schemas.CarburantUpdate):
        changes = data.model_dump(exclude_unset=True)
    else:
        try:
            payload = schemas.CarburantUpdate.model_validate(data)
            changes = payload.model_dump(exclude_unset=True)
        except Exception as exc:
            raise HTTPException(422, exc.errors())
    obj = get_or_404(db, carburant_id)
    if "type_carburant" in changes and changes["type_carburant"] is not None:
        changes["type_carburant"] = changes["type_carburant"].value

    # Les champs obligatoires ne peuvent pas etre mis a null
    for champ in ("vehicule_id", "date_plein", "quantite_litres", "prix_litre",
                  "kilometrage", "type_carburant", "plein_complet"):
        if champ in changes and changes[champ] is None:
            raise HTTPException(422, f"Le champ '{champ}' ne peut pas etre null")

    vehicule_id = changes.get("vehicule_id", obj.vehicule_id)
    chauffeur_id = changes.get("chauffeur_id", obj.chauffeur_id)
    date_plein = changes.get("date_plein", obj.date_plein)
    kilometrage = changes.get("kilometrage", obj.kilometrage)

    verifier_references(vehicule_id, chauffeur_id)
    verifier_affectation(vehicule_id, chauffeur_id)
    verifier_kilometrage(db, vehicule_id, date_plein, kilometrage, exclude_id=obj.id)

    ancien_vehicule_id = obj.vehicule_id
    for champ, valeur in changes.items():
        setattr(obj, champ, valeur)
    obj.montant_total = round(obj.quantite_litres * obj.prix_litre, 2)

    db.commit()
    recalculer_vehicule(db, obj.vehicule_id)
    if ancien_vehicule_id != obj.vehicule_id:
        recalculer_vehicule(db, ancien_vehicule_id)
    db.refresh(obj)
    return obj


@router.delete("/{carburant_id}", status_code=status.HTTP_204_NO_CONTENT)
def supprimer_plein(
    carburant_id: int,
    db: Session = Depends(get_db),
    auth: dict = Depends(require_auth),
):
    if not isinstance(auth, dict):
        auth = {"role": ROLE_ADMIN}
    role = auth.get("role")
    if role == ROLE_CHAUFFEUR:
        raise HTTPException(403, "Lecture seule pour un chauffeur")
    if role not in (ROLE_ADMIN, ROLE_GESTIONNAIRE, "SERVICE"):
        raise HTTPException(403, "Acces refuse")
    obj = get_or_404(db, carburant_id)
    vehicule_id = obj.vehicule_id
    db.delete(obj)
    db.commit()
    recalculer_vehicule(db, vehicule_id)
    return Response(status_code=status.HTTP_204_NO_CONTENT)
