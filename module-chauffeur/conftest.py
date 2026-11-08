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
