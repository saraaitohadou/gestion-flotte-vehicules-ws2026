import os
import time
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from sqlalchemy.exc import OperationalError

import auth
import clients
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
    version="1.3.0",
    lifespan=lifespan,
)

# Configuration CORS pour autoriser les requêtes depuis l'interface React (5173) et autres origines
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Montage des fichiers statiques pour l'espace chauffeur et page de connexion
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
    return {
        "status": "online",
        "message": "module Chauffeur pret !",
        "connexion": "/connexion",
        "espace_chauffeur": "/espace-chauffeur"
    }


# ==========================================
# Pages Web servies directement par FastAPI
# ==========================================

@app.get("/connexion", include_in_schema=False)
@app.get("/login", include_in_schema=False)
def page_connexion():
    html_path = os.path.join(static_dir, "connexion.html")
    if os.path.exists(html_path):
        return FileResponse(html_path)
    return FileResponse(os.path.join(static_dir, "espace-chauffeur.html"))


@app.get("/espace-chauffeur", include_in_schema=False)
def page_espace_chauffeur():
    html_path = os.path.join(static_dir, "espace-chauffeur.html")
    if os.path.exists(html_path):
        return FileResponse(html_path)
    return {"message": "Page espace-chauffeur en cours de déploiement"}


# ==========================================
# Alias pour la connexion admin
# ==========================================

@app.post("/auth/login", tags=["Authentification"])
@app.post("/admin/login", tags=["Authentification"])
def login_alias(data: auth.LoginRequest, request: Request):
    return auth.login(data, request)


# ==========================================
# Alias pour la liste des véhicules
# ==========================================

@app.get("/vehicules-disponibles", tags=["Véhicules"])
@app.get("/api/vehicules-disponibles", tags=["Véhicules"])
def vehicules_disponibles():
    return clients.lister_vehicules()
