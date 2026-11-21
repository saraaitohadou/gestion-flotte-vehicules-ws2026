import os
import time
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from sqlalchemy.exc import OperationalError

import auth
import models
from database import engine
from routers import chauffeurs, espace_chauffeur


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
    description="Gestion des chauffeurs de la flotte et Espace Chauffeur",
    version="1.2.0",
    lifespan=lifespan,
)

# Montage des fichiers statiques pour l'espace chauffeur
current_dir = os.path.dirname(os.path.abspath(__file__))
static_dir = os.path.join(current_dir, "static")
if not os.path.exists(static_dir):
    os.makedirs(static_dir, exist_ok=True)
app.mount("/static", StaticFiles(directory=static_dir), name="static")

# Connexion : publique. Toutes les routes chauffeurs admin : administrateur (ou clé de service).
app.include_router(auth.router)
app.include_router(chauffeurs.router, dependencies=[Depends(auth.require_admin)])

# Espace Chauffeur : authentification chauffeur et routes dédiées
app.include_router(espace_chauffeur.router)


@app.get("/")
def home():
    return {"status": "online", "message": " module Chauffeur pret !", "espace_chauffeur": "/espace-chauffeur"}


@app.get("/espace-chauffeur", include_in_schema=False)
def page_espace_chauffeur():
    html_path = os.path.join(static_dir, "espace-chauffeur.html")
    if os.path.exists(html_path):
        return FileResponse(html_path)
    return {"message": "Page espace-chauffeur en cours de déploiement"}
