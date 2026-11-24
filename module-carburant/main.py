import os
import time
from contextlib import asynccontextmanager

import httpx
from fastapi import FastAPI, HTTPException
from fastapi.staticfiles import StaticFiles
from starlette.responses import Response, FileResponse
from sqlalchemy.exc import OperationalError

import models  # noqa: F401  (enregistre les tables dans Base.metadata)
from routers import carburant


def appliquer_migrations() -> None:
    """Applique les migrations Alembic versionnees (jamais de create_all en production)."""
    from alembic import command
    from alembic.config import Config

    command.upgrade(
        Config(os.path.join(os.path.dirname(os.path.abspath(__file__)), "alembic.ini")),
        "head",
    )


@asynccontextmanager
async def lifespan(app: FastAPI):
    # MySQL peut demarrer plus lentement que le service : on reessaie.
    for _ in range(10):
        try:
            appliquer_migrations()
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


def _references_proxy(url: str, chemin: str, nom: str):
    """Relais same-origin pour les listes de references (CORS/auth cote navigateur)."""
    try:
        reponse = httpx.get(
            url + chemin,
            headers={"X-API-Key": os.getenv("SERVICE_API_KEY", "")},
            timeout=3,
        )
    except httpx.HTTPError:
        raise HTTPException(503, f"Service {nom} indisponible")
    if reponse.status_code != 200:
        raise HTTPException(503, f"Service {nom} a repondu {reponse.status_code}")
    donnees = reponse.json()
    return donnees.get("data", []) if isinstance(donnees, dict) else donnees


@app.get("/references/vehicules", tags=["References"])
def references_vehicules():
    vehicules = _references_proxy(
        os.getenv("VEHICULE_SERVICE_URL", "http://vehicule-service"), "/vehicules", "Vehicule"
    )
    return [
        {
            "id": v.get("id"),
            "label": f"#{v.get('id')} - {v.get('immatriculation', '')} "
                     f"{v.get('marque', '')} {v.get('modele', '')} ({v.get('statut', '')})",
        }
        for v in vehicules
    ]


@app.get("/references/chauffeurs", tags=["References"])
def references_chauffeurs():
    chauffeurs = _references_proxy(
        os.getenv("CHAUFFEUR_SERVICE_URL", "http://chauffeur-service:8000"),
        "/api/chauffeurs", "Chauffeur",
    )
    return [
        {
            "id": c.get("id_chauffeur", c.get("id")),
            "label": f"#{c.get('id_chauffeur', c.get('id'))} - {c.get('prenom', '')} "
                     f"{c.get('nom', '')} ({c.get('cin', '')}, {c.get('statut', '')})",
        }
        for c in chauffeurs
    ]


class FrontendSansCache(StaticFiles):
    async def get_response(self, path: str, scope) -> Response:
        reponse = await super().get_response(path, scope)
        reponse.headers["Cache-Control"] = "no-store, no-cache, must-revalidate, max-age=0"
        reponse.headers["Pragma"] = "no-cache"
        reponse.headers["Expires"] = "0"
        return reponse


# Compatibilite avec les anciennes ressources statiques.
# Elles ne sont pas chargees par le frontend React construit ci-dessous.
@app.get("/ui/app.js", include_in_schema=False)
async def ancienne_ressource_app_js():
    return FileResponse(os.path.join(os.path.dirname(__file__), "static", "app.js"))


@app.get("/ui/style.css", include_in_schema=False)
async def ancienne_ressource_style_css():
    return FileResponse(os.path.join(os.path.dirname(__file__), "static", "style.css"))


# Interface web (HTML/CSS/JS) servie par le service lui-meme : http://127.0.0.1:8000/ui/
# Le build React (`frontend-dist`) est optionnel : on retombe sur l'interface statique
# historique (`static/`) au lieu de faire crasher le service quand il est absent.
_base_dir = os.path.dirname(os.path.abspath(__file__))
_dist_dir = os.path.join(_base_dir, "frontend-dist")
_static_dir = os.path.join(_base_dir, "static")
_ui_dir = _dist_dir if os.path.isdir(_dist_dir) and os.listdir(_dist_dir) else _static_dir
app.mount("/ui", FrontendSansCache(directory=_ui_dir, html=True), name="ui")


@app.get("/", tags=["Sante"])
def accueil():
    return {"service": "gestion-flotte", "module": "carburant", "docs": "/docs", "ui": "/ui/"}
