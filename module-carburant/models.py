from sqlalchemy import Boolean, Column, Date, Float, ForeignKey, Integer, String
from sqlalchemy.orm import relationship

from database import Base


class Vehicule(Base):
    """Version minimale, a remplacer par le module Vehicule de l'equipe."""

    __tablename__ = "vehicules"

    id = Column(Integer, primary_key=True, index=True)
    immatriculation = Column(String, unique=True, nullable=False)
    marque = Column(String, nullable=False)
    modele = Column(String, nullable=False)

    carburants = relationship("Carburant", back_populates="vehicule")


class Chauffeur(Base):
    """Version minimale, a remplacer par le module Chauffeur de l'equipe."""

    __tablename__ = "chauffeurs"

    id = Column(Integer, primary_key=True, index=True)
    nom = Column(String, nullable=False)
    prenom = Column(String, nullable=False)
    telephone = Column(String, nullable=True)

    carburants = relationship("Carburant", back_populates="chauffeur")


class Carburant(Base):
    """Un plein de carburant effectue sur un vehicule."""

    __tablename__ = "carburants"

    id = Column(Integer, primary_key=True, index=True)
    vehicule_id = Column(Integer, ForeignKey("vehicules.id"), nullable=False, index=True)
    chauffeur_id = Column(Integer, ForeignKey("chauffeurs.id"), nullable=True, index=True)
    date_plein = Column(Date, nullable=False, index=True)
    quantite_litres = Column(Float, nullable=False)
    prix_litre = Column(Float, nullable=False)
    montant_total = Column(Float, nullable=False)
    kilometrage = Column(Integer, nullable=False)
    type_carburant = Column(String, nullable=False)
    station = Column(String, nullable=True)
    plein_complet = Column(Boolean, nullable=False, default=True)

    # Calcules automatiquement par le serveur (voir anomalies.py)
    consommation_l_100km = Column(Float, nullable=True)
    anomalie = Column(Boolean, nullable=False, default=False)
    message_anomalie = Column(String, nullable=True)

    vehicule = relationship("Vehicule", back_populates="carburants")
    chauffeur = relationship("Chauffeur", back_populates="carburants")
