from fastapi import FastAPI
import models
from database import engine

models.Base.metadata.create_all(bind=engine)

app = FastAPI(
    title="API Module Chauffeur",
    description="Gestion des chauffeurs de la flotte",
    version="1.0.0"
)

@app.get("/")
def home():
    return {"status": "online", "message": " module Chauffeur pret !"}

