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
app.mount(
    "/ui",
    FrontendSansCache(directory=os.path.join(os.path.dirname(__file__), "frontend-dist"), html=True),
    name="ui",
)


@app.get("/", tags=["Sante"])
def accueil():
    return {"service": "gestion-flotte", "module": "carburant", "docs": "/docs", "ui": "/ui/"}
