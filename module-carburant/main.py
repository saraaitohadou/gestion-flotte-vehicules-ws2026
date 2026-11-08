from fastapi import FastAPI

import models  # noqa: F401  (enregistre les tables)
from database import Base, engine
from routers import carburant, stubs

Base.metadata.create_all(bind=engine)

app = FastAPI(
    title="Gestion de flotte de vehicules",
    description="Module Carburant: pleins, couts et consommation par vehicule.",
    version="0.1.0",
)

app.include_router(carburant.router)
app.include_router(stubs.router)  # a retirer quand les autres modules sont prets


@app.get("/", tags=["Sante"])
def accueil():
    return {"service": "gestion-flotte", "module": "carburant", "docs": "/docs"}
