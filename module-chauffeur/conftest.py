"""Configuration pytest du module Chauffeur.

Les tests unitaires n'utilisent JAMAIS MySQL : une base SQLite en mémoire est
créée pour chaque test, puis détruite. Aucun autre service n'est nécessaire.
"""
import os

# Doit être fait AVANT tout import de database.py (qui crée le moteur au chargement).
os.environ["DATABASE_URL"] = "sqlite://"

import pytest
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker
from sqlalchemy.pool import StaticPool

import models


@pytest.fixture
def db():
    engine = create_engine(
        "sqlite://",
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    models.Base.metadata.create_all(bind=engine)
    session = sessionmaker(bind=engine, autoflush=False, autocommit=False)()
    try:
        yield session
    finally:
        session.close()
        models.Base.metadata.drop_all(bind=engine)
        engine.dispose()

from fastapi import HTTPException
import clients


class FauxVehicules:
    def __init__(self):
        self.statuts, self.en_panne, self._n = {}, False, 0

    def nouveau(self, statut="DISPONIBLE"):
        self._n += 1
        self.statuts[self._n] = statut
        return self._n

    def _controle(self, vid):
        if self.en_panne:
            raise HTTPException(503, detail={"erreur": "VEHICULE_SERVICE_INDISPONIBLE", "message": "down"})
        if vid not in self.statuts:
            raise HTTPException(404, detail={"erreur": "VEHICULE_INTROUVABLE", "message": "?"})

    def verifier_disponibilite(self, vid):
        self._controle(vid)
        if self.statuts[vid] != "DISPONIBLE":
            raise HTTPException(409, detail={"erreur": "VEHICULE_INDISPONIBLE", "message": "?"})

    def changer_statut(self, vid, statut):
        self._controle(vid)
        self.statuts[vid] = statut


@pytest.fixture(autouse=True)
def vehicules(monkeypatch):
    faux = FauxVehicules()
    monkeypatch.setattr(clients, "verifier_disponibilite", faux.verifier_disponibilite)
    monkeypatch.setattr(clients, "changer_statut_vehicule", faux.changer_statut)
    return faux