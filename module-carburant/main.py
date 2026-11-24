import os
import time
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.staticfiles import StaticFiles
from starlette.responses import Response, FileResponse
from sqlalchemy.exc import OperationalError

import models  # noqa: F401  (enregistre les tables)
from database import Base, engine
from routers import carburant

BASE_DIR = os.path.dirname(__file__)


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


@app.middleware("http")
async def accepte_corps_carburant_plat(request, call_next):
    """Accepte l'ancien format { "data": {...} } pour les clients existants."""
    if request.method in ("POST", "PATCH") and request.url.path.startswith("/carburants"):
        body = await request.body()
        if body:
            try:
                import json
                parsed = json.loads(body)
                if isinstance(parsed, dict) and set(parsed) == {"data"} and isinstance(parsed["data"], dict):
                    body = json.dumps(parsed["data"]).encode()
                    request._body = body
                    async def receive():
                        return {"type": "http.request", "body": body, "more_body": False}
                    request._receive = receive
            except Exception:
                pass
    return await call_next(request)

app.include_router(carburant.router)


class FrontendSansCache(StaticFiles):
    async def get_response(self, path: str, scope) -> Response:
        reponse = await super().get_response(path, scope)
        reponse.headers["Cache-Control"] = "no-store, no-cache, must-revalidate, max-age=0"
        reponse.headers["Pragma"] = "no-cache"
        reponse.headers["Expires"] = "0"
        return reponse


# Compatibilite avec les anciennes ressources statiques.
# Elles ne sont pas chargees par le frontend React construit.
@app.get("/ui/app.js", include_in_schema=False)
async def ancienne_ressource_app_js():
    return FileResponse(os.path.join(BASE_DIR, "static", "app.js"))


@app.get("/ui/style.css", include_in_schema=False)
async def ancienne_ressource_style_css():
    return FileResponse(os.path.join(BASE_DIR, "static", "style.css"))


# Interface web servie par le service lui-meme : http://127.0.0.1:8000/ui/
# On utilise l'interface React compilee (frontend-dist) si elle existe,
# sinon l'interface HTML/JS simple (static), toujours presente dans le depot.
DOSSIER_UI = os.path.join(BASE_DIR, "frontend-dist")
if not os.path.isdir(DOSSIER_UI):
    DOSSIER_UI = os.path.join(BASE_DIR, "static")

app.mount("/ui", FrontendSansCache(directory=DOSSIER_UI, html=True), name="ui")


@app.get("/", tags=["Sante"])
def accueil():
    return {"service": "gestion-flotte", "module": "carburant", "docs": "/docs", "ui": "/ui/"}