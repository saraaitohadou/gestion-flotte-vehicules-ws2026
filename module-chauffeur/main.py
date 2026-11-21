import time
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI
from sqlalchemy.exc import OperationalError

import auth
import models
from database import engine
from routers import chauffeurs


@asynccontextmanager
async def lifespan(app: FastAPI):
    for _ in range(10):
        try:
            models.Base.metadata.create_all(bind=engine)
            break
        except OperationalError:
            time.sleep(3)
    yield


app = FastAPI(
    title="API Module Chauffeur",
    description="Gestion des chauffeurs de la flotte",
    version="1.1.0",
    lifespan=lifespan,
)

# Connexion : publique. Toutes les routes chauffeurs : administrateur (ou clé de service).
app.include_router(auth.router)
app.include_router(chauffeurs.router, dependencies=[Depends(auth.require_admin)])


@app.get("/")
def home():
    return {"status": "online", "message": " module Chauffeur pret !"}
