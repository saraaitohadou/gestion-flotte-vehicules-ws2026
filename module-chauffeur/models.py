from sqlalchemy import Column, Integer, String, Date, Enum, DateTime ,func
import enum
from database import Base

class StatutChauffeur(str, enum.Enum):
    DISPONIBLE = "DISPONIBLE"
    EN_MISSION = "EN_MISSION"
    EN_CONGE = "EN_CONGE"
    INACTIF = "INACTIF"

class Chauffeur(Base):
    __tablename__ = "chauffeurs"

    id_chauffeur = Column(Integer, primary_key=True, index=True, autoincrement=True)
    cin = Column(String(20), unique=True, nullable=False)
    nom = Column(String(50), nullable=False)
    prenom = Column(String(50), nullable=False)
    telephone = Column(String(20), nullable=True)
    numero_permis = Column(String(30), unique=True, nullable=False)
    categorie_permis = Column(String(10), nullable=False)
    date_expiration_permis = Column(Date, nullable=False)
    statut = Column(Enum(StatutChauffeur), nullable=False, default=StatutChauffeur.DISPONIBLE)
    created_at = Column(DateTime, server_default=func.now(), nullable=False)
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now(), nullable=False)