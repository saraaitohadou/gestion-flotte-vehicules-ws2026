import time
from contextlib import asynccontextmanager
from fastapi import FastAPI
from sqlalchemy.exc import OperationalError
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
    version="1.0.0",
    lifespan=lifespan
)
app.include_router(chauffeurs.router)

@app.get("/")
def home():
    return {"status": "online", "message": " module Chauffeur pret !"}

