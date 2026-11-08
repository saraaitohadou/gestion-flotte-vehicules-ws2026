"""Detection de surconsommation (alerte pour l'administrateur).

Principe: pour chaque vehicule, on parcourt ses pleins dans l'ordre. La
consommation d'un plein = litres / km parcourus depuis le plein precedent x 100.
Si elle depasse la moyenne des consommations precedentes du vehicule de plus
de SEUIL_SURCONSOMMATION, le plein est marque comme anomalie.

Exemple: un vehicule fait toujours 7 L/100 km, un jour il fait 14 -> alerte.
"""
import os

from sqlalchemy.orm import Session

import models

# Ecart tolere par rapport a la moyenne (0.30 = +30 %). Modifiable sans toucher au code:
#   SEUIL_SURCONSOMMATION=0.25 uvicorn main:app
SEUIL_SURCONSOMMATION = float(os.getenv("SEUIL_SURCONSOMMATION", "0.30"))

# Nombre minimum de consommations deja connues pour pouvoir juger un plein
HISTORIQUE_MIN = int(os.getenv("HISTORIQUE_MIN", "2"))


def recalculer_vehicule(db: Session, vehicule_id: int) -> None:
    """Recalcule consommation et anomalie de tous les pleins d'un vehicule.

    On recalcule tout (et pas seulement le dernier plein) car modifier ou
    supprimer un plein, ou en inserer un entre deux dates, change les
    consommations des pleins qui suivent.
    """
    pleins = (
        db.query(models.Carburant)
        .filter(models.Carburant.vehicule_id == vehicule_id)
        .order_by(models.Carburant.date_plein, models.Carburant.kilometrage, models.Carburant.id)
        .all()
    )

    precedent = None
    historique = []  # consommations deja calculees, pour la moyenne

    for plein in pleins:
        plein.consommation_l_100km = None
        plein.anomalie = False
        plein.message_anomalie = None

        if precedent is not None and plein.plein_complet:
            distance = plein.kilometrage - precedent.kilometrage
            if distance > 0:
                conso = round(plein.quantite_litres / distance * 100, 2)
                plein.consommation_l_100km = conso

                if len(historique) >= HISTORIQUE_MIN:
                    moyenne = sum(historique) / len(historique)
                    limite = moyenne * (1 + SEUIL_SURCONSOMMATION)
                    if conso > limite:
                        plein.anomalie = True
                        plein.message_anomalie = (
                            f"Surconsommation: {conso} L/100 km "
                            f"pour une moyenne habituelle de {round(moyenne, 2)} L/100 km "
                            f"(seuil {round(limite, 2)})"
                        )
                historique.append(conso)

        precedent = plein

    db.commit()
