"""Lance le module Carburant en local pour tester l'interface, SANS Docker ni MySQL.

- Base SQLite (fichier dev.db) au lieu de MySQL
- Faux services Vehicule/Chauffeur: les ids 1 a 5 existent, les autres donnent 404
  (mettre PANNE=True pour simuler une erreur 503)

Usage:  python run_front.py   ->  http://127.0.0.1:8000/ui/
"""
import os

os.environ["DATABASE_URL"] = "sqlite:///./dev.db"

import uvicorn
from fastapi import HTTPException

import clients

IDS_VEHICULES = {1, 2, 3, 4, 5}
IDS_CHAUFFEURS = {1, 2, 3, 4, 5}
PANNE = False


def _controle(ident, connus, nom):
    if PANNE:
        raise HTTPException(503, f"Service {nom} indisponible")
    if ident not in connus:
        raise HTTPException(404, f"{nom} {ident} introuvable")


clients.verifier_vehicule = lambda i: _controle(i, IDS_VEHICULES, "Vehicule")
clients.verifier_chauffeur = lambda i: _controle(i, IDS_CHAUFFEURS, "Chauffeur")

if __name__ == "__main__":
    port = int(os.getenv("PORT", "8000"))
    uvicorn.run("main:app", host="127.0.0.1", port=port)