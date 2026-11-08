from datetime import date
from typing import Optional

from fastapi import HTTPException, status

from models import StatutChauffeur

S = StatutChauffeur

TRANSITIONS = {
    S.DISPONIBLE: {S.EN_MISSION, S.EN_CONGE, S.INACTIF},
    S.EN_MISSION: {S.DISPONIBLE},
    S.EN_CONGE: {S.DISPONIBLE, S.INACTIF},
    S.INACTIF: {S.DISPONIBLE},
}

STATUTS_EXIGEANT_PERMIS_VALIDE = {S.DISPONIBLE, S.EN_MISSION}


def permis_est_valide(date_expiration: date, aujourdhui: Optional[date] = None) -> bool:
    """Valide uniquement si la date d'expiration est STRICTEMENT après aujourd'hui."""
    aujourdhui = aujourdhui or date.today()
    return date_expiration > aujourdhui


def verifier_changement_statut(actuel: StatutChauffeur, cible: StatutChauffeur,
                               date_expiration_permis: date,
                               aujourdhui: Optional[date] = None) -> None:
    """Lève une HTTPException 409 si le changement de statut est interdit."""
    if actuel == cible:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={"erreur": "STATUT_IDENTIQUE",
                    "message": f"Le chauffeur est déjà au statut {actuel.value}."},
        )

    if actuel == S.EN_MISSION and cible in (S.EN_CONGE, S.INACTIF):
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={"erreur": "MISSION_NON_CLOTUREE",
                    "message": "Impossible de passer en congé ou inactif : "
                               "la mission en cours n'est pas clôturée."},
        )

    if cible not in TRANSITIONS[actuel]:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={"erreur": "TRANSITION_INTERDITE",
                    "message": f"Transition {actuel.value} → {cible.value} interdite."},
        )

    if cible in STATUTS_EXIGEANT_PERMIS_VALIDE and not permis_est_valide(
            date_expiration_permis, aujourdhui):
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={"erreur": "PERMIS_EXPIRE",
                    "message": f"Permis expiré ou expirant aujourd'hui "
                               f"({date_expiration_permis}) : statut {cible.value} refusé."},
        )


 # Quels permis permettent de conduire quelle catégorie de véhicule.
# HYPOTHÈSE : un permis C ou D couvre aussi un véhicule B. À ajuster ici seulement.
COMPATIBILITE_PERMIS = {
    "A": {"A"},
    "A1": {"A1", "A"},
    "B": {"B", "C", "D"},
    "C": {"C"},
    "D": {"D"},
    "EB": {"EB"},
    "EC": {"EC"},
    "ED": {"ED"},
}
STATUTS_SELECTIONNABLES = {S.DISPONIBLE, S.EN_MISSION}


def categorie_compatible(categorie_permis: str, categorie_vehicule: str) -> bool:
    acceptes = COMPATIBILITE_PERMIS.get(categorie_vehicule.strip().upper(), set())
    return categorie_permis.strip().upper() in acceptes


def est_selectionnable(statut: StatutChauffeur) -> bool:
    """Sélectionnable par les autres modules (ex. Carburant) : jamais EN_CONGE ni INACTIF."""
    return statut in STATUTS_SELECTIONNABLES


def evaluer_eligibilite(statut: StatutChauffeur, date_expiration_permis: date,
                        aujourdhui: Optional[date] = None) -> dict:
    valide = permis_est_valide(date_expiration_permis, aujourdhui)
    motifs = []
    if statut == S.INACTIF:
        motifs.append("CHAUFFEUR_INACTIF")
    elif statut == S.EN_CONGE:
        motifs.append("CHAUFFEUR_EN_CONGE")
    elif statut == S.EN_MISSION:
        motifs.append("DEJA_EN_MISSION")
    if not valide:
        motifs.append("PERMIS_EXPIRE")
    return {
        "permis_valide": valide,
        "selectionnable": est_selectionnable(statut),
        "affectable": statut == S.DISPONIBLE and valide,
        "motifs": motifs,
    }