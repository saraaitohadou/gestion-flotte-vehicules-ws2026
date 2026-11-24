from datetime import date, timedelta
from typing import Optional

from fastapi import APIRouter, Depends, Header, HTTPException, Query, status
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

import auth
import clients
import models
import regles
import schemas
from database import get_db

router = APIRouter(prefix="/api/chauffeurs", tags=["Chauffeurs"])


def get_chauffeur_actif_or_404(db: Session, id_chauffeur: int) -> models.Chauffeur:
    """Retourne le chauffeur, ou lève 404 s'il n'existe pas ou est INACTIF."""
    chauffeur = (
        db.query(models.Chauffeur)
        .filter(
            models.Chauffeur.id_chauffeur == id_chauffeur,
            models.Chauffeur.statut != models.StatutChauffeur.INACTIF,
        )
        .first()
    )
    if chauffeur is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={
                "erreur": "CHAUFFEUR_INTROUVABLE",
                "message": f"Aucun chauffeur actif avec l'id {id_chauffeur}.",
            },
        )
    return chauffeur


def categorie_vehicule_valide(valeur: str) -> str:
    v = valeur.strip().upper()
    if v not in schemas.CATEGORIES_PERMIS:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "erreur": "CATEGORIE_VEHICULE_INVALIDE",
                "message": f"Catégories acceptées : {sorted(schemas.CATEGORIES_PERMIS)}",
            },
        )
    return v


def _conflit(existant: models.Chauffeur, code: str, message: str):
    if existant.statut == models.StatutChauffeur.INACTIF:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "erreur": "CHAUFFEUR_INACTIF_EXISTANT",
                "id_chauffeur": existant.id_chauffeur,
                "message": f"{message} Il appartient à un chauffeur désactivé : "
                           f"utilisez POST /api/chauffeurs/{existant.id_chauffeur}/reactiver.",
            },
        )
    raise HTTPException(
        status_code=status.HTTP_409_CONFLICT,
        detail={"erreur": code, "message": message},
    )


def verifier_unicite(
    db: Session,
    cin: str | None,
    numero_permis: str | None,
    exclure_id: int | None = None,
):
    """Lève une erreur 409 si le CIN ou le numéro de permis existe déjà."""
    if cin is not None:
        q = db.query(models.Chauffeur).filter(models.Chauffeur.cin == cin)
        if exclure_id is not None:
            q = q.filter(models.Chauffeur.id_chauffeur != exclure_id)
        existant = q.first()
        if existant:
            _conflit(existant, "CIN_DEJA_UTILISE", f"Le CIN {cin} existe déjà.")

    if numero_permis is not None:
        q = db.query(models.Chauffeur).filter(models.Chauffeur.numero_permis == numero_permis)
        if exclure_id is not None:
            q = q.filter(models.Chauffeur.id_chauffeur != exclure_id)
        existant = q.first()
        if existant:
            _conflit(
                existant,
                "PERMIS_DEJA_UTILISE",
                f"Le numéro de permis {numero_permis} existe déjà.",
            )


def _verifier_vehicule_libre(
    db: Session,
    vehicule_id: int,
    id_chauffeur_courant: int,
) -> None:
    """Lève 409 si un autre chauffeur actif a déjà ce vehicule_id."""
    deja_affecte = (
        db.query(models.Chauffeur)
        .filter(
            models.Chauffeur.vehicule_id == vehicule_id,
            models.Chauffeur.id_chauffeur != id_chauffeur_courant,
            models.Chauffeur.statut != models.StatutChauffeur.INACTIF,
        )
        .with_for_update()
        .first()
    )
    if deja_affecte is not None:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "erreur": "VEHICULE_DEJA_AFFECTE",
                "message": f"Le véhicule {vehicule_id} est déjà affecté au chauffeur "
                           f"#{deja_affecte.id_chauffeur} ({deja_affecte.statut.value}).",
            },
        )


@router.post("", response_model=schemas.ChauffeurOut, status_code=status.HTTP_201_CREATED)
def creer_chauffeur(
    data: schemas.ChauffeurCreate,
    db: Session = Depends(get_db),
    authorization: Optional[str] = Header(default=None),
    _admin: dict = Depends(auth.require_admin),
):
    verifier_unicite(db, data.cin, data.numero_permis)
    if not regles.permis_est_valide(data.date_expiration_permis):
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "erreur": "PERMIS_EXPIRE",
                "message": "Impossible de créer un chauffeur avec un permis expiré.",
            },
        )

    if data.vehicule_id is not None:
        deja_affecte = (
            db.query(models.Chauffeur)
            .filter(
                models.Chauffeur.vehicule_id == data.vehicule_id,
                models.Chauffeur.statut != models.StatutChauffeur.INACTIF,
            )
            .with_for_update()
            .first()
        )
        if deja_affecte is not None:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail={
                    "erreur": "VEHICULE_DEJA_AFFECTE",
                    "message": f"Le véhicule {data.vehicule_id} est déjà affecté au chauffeur "
                               f"#{deja_affecte.id_chauffeur} ({deja_affecte.statut.value}).",
                },
            )

    chauffeur = models.Chauffeur(**data.model_dump())
    db.add(chauffeur)
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "erreur": "DOUBLON",
                "message": "Le CIN ou le numéro de permis existe déjà.",
            },
        )
    db.refresh(chauffeur)
    if chauffeur.vehicule_id:
        try:
            setattr(
                chauffeur,
                "vehicule",
                clients.obtenir_vehicule(chauffeur.vehicule_id, bearer_token=authorization),
            )
        except Exception:
            pass
    return chauffeur


@router.put("/{id_chauffeur}", response_model=schemas.ChauffeurOut)
def modifier_chauffeur(
    id_chauffeur: int,
    data: schemas.ChauffeurUpdate,
    db: Session = Depends(get_db),
    authorization: Optional[str] = Header(default=None),
    _admin: dict = Depends(auth.require_admin),
):
    chauffeur = (
        db.query(models.Chauffeur)
        .filter(
            models.Chauffeur.id_chauffeur == id_chauffeur,
            models.Chauffeur.statut != models.StatutChauffeur.INACTIF,
        )
        .with_for_update()
        .first()
    )
    if chauffeur is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={
                "erreur": "CHAUFFEUR_INTROUVABLE",
                "message": f"Aucun chauffeur actif avec l'id {id_chauffeur}.",
            },
        )

    changements = data.model_dump(exclude_unset=True)
    verifier_unicite(
        db,
        changements.get("cin"),
        changements.get("numero_permis"),
        exclure_id=id_chauffeur,
    )

    nouvelle_date = changements.get("date_expiration_permis")
    if (
        nouvelle_date is not None
        and chauffeur.statut in (models.StatutChauffeur.DISPONIBLE, models.StatutChauffeur.EN_MISSION)
        and not regles.permis_est_valide(nouvelle_date)
    ):
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "erreur": "PERMIS_EXPIRE",
                "message": "La nouvelle date d'expiration est déjà dépassée.",
            },
        )

    ancien_vehicule_id = chauffeur.vehicule_id
    nouveau_vehicule_id = changements.get("vehicule_id")

    if (
        "vehicule_id" in changements
        and nouveau_vehicule_id is not None
        and nouveau_vehicule_id != ancien_vehicule_id
    ):
        _verifier_vehicule_libre(db, nouveau_vehicule_id, id_chauffeur)
        clients.verifier_disponibilite(nouveau_vehicule_id, bearer_token=authorization)
        clients.changer_statut_vehicule(
            nouveau_vehicule_id, "EN_MISSION", bearer_token=authorization
        )
        chauffeur.statut = models.StatutChauffeur.EN_MISSION

    for champ, valeur in changements.items():
        setattr(chauffeur, champ, valeur)

    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        if (
            "vehicule_id" in changements
            and nouveau_vehicule_id is not None
            and nouveau_vehicule_id != ancien_vehicule_id
        ):
            try:
                clients.changer_statut_vehicule(
                    nouveau_vehicule_id, "DISPONIBLE", bearer_token=authorization
                )
            except Exception:
                pass
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "erreur": "DOUBLON",
                "message": "Le CIN ou le numéro de permis existe déjà.",
            },
        )
    except Exception:
        db.rollback()
        if (
            "vehicule_id" in changements
            and nouveau_vehicule_id is not None
            and nouveau_vehicule_id != ancien_vehicule_id
        ):
            try:
                clients.changer_statut_vehicule(
                    nouveau_vehicule_id, "DISPONIBLE", bearer_token=authorization
                )
            except Exception:
                pass
        raise

    if (
        "vehicule_id" in changements
        and ancien_vehicule_id is not None
        and ancien_vehicule_id != nouveau_vehicule_id
    ):
        try:
            clients.changer_statut_vehicule(
                ancien_vehicule_id, "DISPONIBLE", bearer_token=authorization
            )
        except Exception:
            pass

    db.refresh(chauffeur)
    if chauffeur.vehicule_id:
        try:
            setattr(
                chauffeur,
                "vehicule",
                clients.obtenir_vehicule(chauffeur.vehicule_id, bearer_token=authorization),
            )
        except Exception:
            pass
    return chauffeur


@router.patch("/{id_chauffeur}/statut", response_model=schemas.ChauffeurOut)
def changer_statut(
    id_chauffeur: int,
    data: schemas.ChauffeurStatutUpdate,
    db: Session = Depends(get_db),
    authorization: Optional[str] = Header(default=None),
    _admin: dict = Depends(auth.require_admin),
):
    chauffeur = get_chauffeur_actif_or_404(db, id_chauffeur)

    if data.statut == models.StatutChauffeur.INACTIF:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "erreur": "UTILISER_DELETE",
                "message": "Pour désactiver un chauffeur, utilisez DELETE /api/chauffeurs/{id}.",
            },
        )

    regles.verifier_changement_statut(
        chauffeur.statut, data.statut, chauffeur.date_expiration_permis
    )

    ancien_vehicule_id = chauffeur.vehicule_id
    if data.statut in (models.StatutChauffeur.DISPONIBLE, models.StatutChauffeur.EN_CONGE):
        chauffeur.vehicule_id = None

    chauffeur.statut = data.statut
    db.commit()
    db.refresh(chauffeur)

    if (
        data.statut in (models.StatutChauffeur.DISPONIBLE, models.StatutChauffeur.EN_CONGE)
        and ancien_vehicule_id is not None
    ):
        try:
            clients.changer_statut_vehicule(
                ancien_vehicule_id, "DISPONIBLE", bearer_token=authorization
            )
        except Exception:
            pass

    return chauffeur

@router.post("/{id_chauffeur}/reactiver", response_model=schemas.ChauffeurOut)
def reactiver_chauffeur(
    id_chauffeur: int,
    data: Optional[schemas.ChauffeurReactivation] = None,
    db: Session = Depends(get_db),
    _admin: dict = Depends(auth.require_admin),
):
    chauffeur = (
        db.query(models.Chauffeur)
        .filter(models.Chauffeur.id_chauffeur == id_chauffeur)
        .first()
    )
    if chauffeur is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={
                "erreur": "CHAUFFEUR_INTROUVABLE",
                "message": f"Aucun chauffeur avec l'id {id_chauffeur}.",
            },
        )

    if chauffeur.statut != models.StatutChauffeur.INACTIF:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "erreur": "CHAUFFEUR_DEJA_ACTIF",
                "message": f"Le chauffeur est déjà actif ({chauffeur.statut.value}).",
            },
        )

    date_permis = (
        data.date_expiration_permis
        if data and data.date_expiration_permis
        else chauffeur.date_expiration_permis
    )

    if not regles.permis_est_valide(date_permis):
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "erreur": "PERMIS_EXPIRE",
                "message": "Permis expiré : envoyez une nouvelle "
                           "date_expiration_permis pour réactiver le chauffeur.",
            },
        )

    chauffeur.date_expiration_permis = date_permis
    chauffeur.statut = models.StatutChauffeur.DISPONIBLE
    db.commit()
    db.refresh(chauffeur)
    return chauffeur


@router.get("/vehicules-disponibles")
def vehicules_disponibles(
    authorization: Optional[str] = Header(default=None),
    _admin: dict = Depends(auth.require_admin),
):
    """Récupère la liste des véhicules depuis vehicule-service pour attribution."""
    return clients.lister_vehicules_disponibles(bearer_token=authorization)


@router.get("", response_model=list[schemas.ChauffeurOut])
def lister_chauffeurs(
    statut: Optional[models.StatutChauffeur] = None,
    categorie_permis: Optional[str] = None,
    skip: int = Query(0, ge=0),
    limit: int = Query(50, ge=1, le=200),
    db: Session = Depends(get_db),
    authorization: Optional[str] = Header(default=None),
):
    q = db.query(models.Chauffeur).filter(
        models.Chauffeur.statut != models.StatutChauffeur.INACTIF
    )
    if statut is not None:
        q = q.filter(models.Chauffeur.statut == statut)
    if categorie_permis is not None:
        q = q.filter(models.Chauffeur.categorie_permis == categorie_permis.strip().upper())
    chauffeurs = q.order_by(models.Chauffeur.id_chauffeur).offset(skip).limit(limit).all()

    try:
        vehicules = clients.lister_vehicules(bearer_token=authorization)
        v_map = {v.get("id"): v for v in vehicules if isinstance(v, dict)}
        for c in chauffeurs:
            if c.vehicule_id:
                setattr(c, "vehicule", v_map.get(c.vehicule_id))
    except Exception:
        pass

    return chauffeurs


@router.get("/disponibles", response_model=list[schemas.ChauffeurOut])
def chauffeurs_disponibles(
    categorie_vehicule: Optional[str] = None,
    db: Session = Depends(get_db),
):
    chauffeurs = (
        db.query(models.Chauffeur)
        .filter(
            models.Chauffeur.statut == models.StatutChauffeur.DISPONIBLE,
            models.Chauffeur.date_expiration_permis > date.today(),
        )
        .order_by(models.Chauffeur.id_chauffeur)
        .all()
    )
    if categorie_vehicule is not None:
        cat = categorie_vehicule_valide(categorie_vehicule)
        chauffeurs = [
            c for c in chauffeurs if regles.categorie_compatible(c.categorie_permis, cat)
        ]
    return chauffeurs


@router.get("/permis-expires", response_model=list[schemas.ChauffeurOut])
def permis_expires(jours: int = Query(30, ge=0, le=365), db: Session = Depends(get_db)):
    """Permis déjà expirés ou qui expirent dans les N prochains jours."""
    limite = date.today() + timedelta(days=jours)
    return (
        db.query(models.Chauffeur)
        .filter(
            models.Chauffeur.statut != models.StatutChauffeur.INACTIF,
            models.Chauffeur.date_expiration_permis <= limite,
        )
        .order_by(models.Chauffeur.date_expiration_permis)
        .all()
    )


@router.get("/{id_chauffeur}", response_model=schemas.ChauffeurOut)
def lire_chauffeur(
    id_chauffeur: int,
    db: Session = Depends(get_db),
    authorization: Optional[str] = Header(default=None),
):
    chauffeur = get_chauffeur_actif_or_404(db, id_chauffeur)
    if chauffeur.vehicule_id:
        try:
            setattr(
                chauffeur,
                "vehicule",
                clients.obtenir_vehicule(chauffeur.vehicule_id, bearer_token=authorization),
            )
        except Exception:
            pass
    return chauffeur


@router.get("/{id_chauffeur}/compatibilite", response_model=schemas.CompatibiliteOut)
def verifier_compatibilite(
    id_chauffeur: int,
    categorie_vehicule: str,
    db: Session = Depends(get_db),
):
    cat = categorie_vehicule_valide(categorie_vehicule)
    chauffeur = get_chauffeur_actif_or_404(db, id_chauffeur)
    return {
        "id_chauffeur": chauffeur.id_chauffeur,
        "categorie_permis": chauffeur.categorie_permis,
        "categorie_vehicule": cat,
        "compatible": regles.categorie_compatible(chauffeur.categorie_permis, cat),
    }


@router.get("/{id_chauffeur}/eligibilite", response_model=schemas.EligibiliteOut)
def verifier_eligibilite(id_chauffeur: int, db: Session = Depends(get_db)):
    """404 seulement si l'id n'existe pas. Un INACTIF répond 200 avec selectionnable=false."""
    chauffeur = (
        db.query(models.Chauffeur)
        .filter(models.Chauffeur.id_chauffeur == id_chauffeur)
        .first()
    )
    if chauffeur is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={
                "erreur": "CHAUFFEUR_INTROUVABLE",
                "message": f"Aucun chauffeur avec l'id {id_chauffeur}.",
            },
        )
    return {
        "id_chauffeur": chauffeur.id_chauffeur,
        "statut": chauffeur.statut,
        **regles.evaluer_eligibilite(chauffeur.statut, chauffeur.date_expiration_permis),
    }


@router.post("/{id_chauffeur}/affecter", response_model=schemas.ChauffeurOut)
def affecter_mission(
    id_chauffeur: int,
    data: schemas.AffectationRequest,
    db: Session = Depends(get_db),
    authorization: Optional[str] = Header(default=None),
    _admin: dict = Depends(auth.require_admin),
):
    """Affecte un chauffeur à un véhicule : DISPONIBLE -> EN_MISSION des deux côtés."""
    chauffeur = (
        db.query(models.Chauffeur)
        .filter(
            models.Chauffeur.id_chauffeur == id_chauffeur,
            models.Chauffeur.statut != models.StatutChauffeur.INACTIF,
        )
        .with_for_update()
        .first()
    )
    if chauffeur is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={
                "erreur": "CHAUFFEUR_INTROUVABLE",
                "message": f"Aucun chauffeur actif avec l'id {id_chauffeur}.",
            },
        )

    regles.verifier_changement_statut(
        chauffeur.statut,
        models.StatutChauffeur.EN_MISSION,
        chauffeur.date_expiration_permis,
    )

    if data.categorie_vehicule:
        cat = categorie_vehicule_valide(data.categorie_vehicule)
        if not regles.categorie_compatible(chauffeur.categorie_permis, cat):
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail={
                    "erreur": "CATEGORIE_INCOMPATIBLE",
                    "message": f"Le permis {chauffeur.categorie_permis} ne permet pas "
                               f"de conduire un véhicule de catégorie {cat}.",
                },
            )

    _verifier_vehicule_libre(db, data.vehicule_id, id_chauffeur)

    clients.verifier_disponibilite(data.vehicule_id, bearer_token=authorization)
    clients.changer_statut_vehicule(data.vehicule_id, "EN_MISSION", bearer_token=authorization)

    try:
        chauffeur.statut = models.StatutChauffeur.EN_MISSION
        chauffeur.vehicule_id = data.vehicule_id
        db.commit()
    except Exception:
        db.rollback()
        clients.changer_statut_vehicule(
            data.vehicule_id, "DISPONIBLE", bearer_token=authorization
        )
        raise
    db.refresh(chauffeur)
    return chauffeur


@router.post("/{id_chauffeur}/cloturer-mission", response_model=schemas.ChauffeurOut)
def cloturer_mission(
    id_chauffeur: int,
    db: Session = Depends(get_db),
    authorization: Optional[str] = Header(default=None),
    _admin: dict = Depends(auth.require_admin),
):
    """Fin de mission : le véhicule repasse DISPONIBLE, puis le chauffeur aussi."""
    chauffeur = get_chauffeur_actif_or_404(db, id_chauffeur)

    if chauffeur.statut != models.StatutChauffeur.EN_MISSION:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "erreur": "PAS_EN_MISSION",
                "message": f"Le chauffeur n'est pas en mission ({chauffeur.statut.value}).",
            },
        )

    regles.verifier_changement_statut(
        chauffeur.statut,
        models.StatutChauffeur.DISPONIBLE,
        chauffeur.date_expiration_permis,
    )

    if chauffeur.vehicule_id is not None:
        clients.changer_statut_vehicule(
            chauffeur.vehicule_id, "DISPONIBLE", bearer_token=authorization
        )

    chauffeur.statut = models.StatutChauffeur.DISPONIBLE
    chauffeur.vehicule_id = None
    db.commit()
    db.refresh(chauffeur)
    return chauffeur


@router.delete("/{id_chauffeur}", status_code=status.HTTP_204_NO_CONTENT)
def supprimer_chauffeur(
    id_chauffeur: int,
    db: Session = Depends(get_db),
    _admin: dict = Depends(auth.require_admin),
):
    """Suppression LOGIQUE : le chauffeur passe à INACTIF, la ligne reste en base."""
    chauffeur = get_chauffeur_actif_or_404(db, id_chauffeur)

    if chauffeur.statut == models.StatutChauffeur.EN_MISSION:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "erreur": "CHAUFFEUR_EN_MISSION",
                "message": "Impossible de désactiver un chauffeur en mission : "
                           "clôturez d'abord sa mission.",
            },
        )



    chauffeur.statut = models.StatutChauffeur.INACTIF
    db.commit()

@router.post("/sync-vehicule/{vehicule_id}")
def synchroniser_chauffeur_depuis_vehicule(
    vehicule_id: int,
    payload: dict,
    db: Session = Depends(get_db),
    _admin: dict = Depends(auth.require_admin),
):
    """Synchronise le chauffeur affecté lorsque le statut d'un véhicule change."""
    statut_vehicule = str(payload.get("statut", "")).upper().strip()
    chauffeur = (
        db.query(models.Chauffeur)
        .filter(
            models.Chauffeur.vehicule_id == vehicule_id,
            models.Chauffeur.statut != models.StatutChauffeur.INACTIF,
        )
        .first()
    )

    if statut_vehicule == "EN_MISSION":
        if chauffeur is None:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail={
                    "erreur": "CHAUFFEUR_NON_AFFECTE",
                    "message": f"Impossible de passer le véhicule #{vehicule_id} en mission : aucun chauffeur n'est affecté.",
                },
            )
        if chauffeur.statut == models.StatutChauffeur.EN_CONGE:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail={
                    "erreur": "CHAUFFEUR_EN_CONGE",
                    "message": f"Le chauffeur #{chauffeur.id_chauffeur} affecté à ce véhicule est en congé.",
                },
            )
        if chauffeur.statut == models.StatutChauffeur.DISPONIBLE:
            regles.verifier_permis_valide(chauffeur.date_expiration_permis, "EN_MISSION")
            chauffeur.statut = models.StatutChauffeur.EN_MISSION
            db.commit()
            db.refresh(chauffeur)
        return {"synced": True, "chauffeur_id": chauffeur.id_chauffeur, "statut": chauffeur.statut.value}

    if statut_vehicule in ("DISPONIBLE", "EN_MAINTENANCE", "HORS_SERVICE"):
        if chauffeur is not None:
            if chauffeur.statut == models.StatutChauffeur.EN_MISSION:
                chauffeur.statut = models.StatutChauffeur.DISPONIBLE
            chauffeur.vehicule_id = None
            db.commit()
            db.refresh(chauffeur)
            return {"synced": True, "chauffeur_id": chauffeur.id_chauffeur, "statut": chauffeur.statut.value}

    return {"synced": False}