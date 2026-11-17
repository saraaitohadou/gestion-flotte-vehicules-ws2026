import time
from contextlib import asynccontextmanager

from fastapi import FastAPI
from sqlalchemy.exc import OperationalError

import models  # noqa: F401  (enregistre les tables)
from database import Base, engine
from routers import carburant


@asynccontextmanager
async def lifespan(app: FastAPI):
    # MySQL peut demarrer plus lentement que le service : on reessaie.
    for _ in range(10):
        try:
            Base.metadata.create_all(bind=engine)
            break
        except OperationalError:
            time.sleep(3)
    else:
        raise RuntimeError("Base de donnees injoignable apres 10 tentatives")
    yield


app = FastAPI(
    title="Gestion de flotte de vehicules",
    description="Module Carburant: pleins, couts et consommation par vehicule.",
    version="0.2.0",
    lifespan=lifespan,
)

app.include_router(carburant.router)


@app.get("/", tags=["Sante"])
def accueil():
    return {"service": "gestion-flotte", "module": "carburant", "docs": "/docs"}
