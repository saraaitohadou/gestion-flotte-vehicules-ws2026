from sqlalchemy import Boolean, Column, Date, Float, Integer, String

from database import Base


class Carburant(Base):
    """Un plein de carburant effectue sur un vehicule."""

    __tablename__ = "carburants"

    id = Column(Integer, primary_key=True, index=True)

    # Simples identifiants : les vehicules et chauffeurs sont geres par les
    # autres services (verification par appel HTTP, pas de cle etrangere).
    vehicule_id = Column(Integer, nullable=False, index=True)
    chauffeur_id = Column(Integer, nullable=True, index=True)

    date_plein = Column(Date, nullable=False, index=True)
    quantite_litres = Column(Float, nullable=False)
    prix_litre = Column(Float, nullable=False)
    montant_total = Column(Float, nullable=False)
    kilometrage = Column(Integer, nullable=False)
    type_carburant = Column(String(20), nullable=False)
    station = Column(String(100), nullable=True)
    plein_complet = Column(Boolean, nullable=False, default=True)

    # Calcules automatiquement par le serveur (voir anomalies.py)
    consommation_l_100km = Column(Float, nullable=True)
    anomalie = Column(Boolean, nullable=False, default=False)
    message_anomalie = Column(String(255), nullable=True)
