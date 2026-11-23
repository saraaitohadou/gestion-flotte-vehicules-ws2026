"""Fixtures communes aux tests du module Carburant.

Les services Vehicule et Chauffeur sont simules: aucun test n'a besoin de
Docker, de MySQL ni des autres services.
"""
import pytest
from fastapi import HTTPException

import clients
import auth


class FauxServices:
    """Simule les reponses des services Vehicule et Chauffeur."""

    def __init__(self):
        self.vehicules = set()
        self.chauffeurs = set()
        self.en_panne = set()  # contient "Vehicule" et/ou "Chauffeur"
        self._nv = 0
        self._nc = 0
        self.affectations = {}

    def nouveau_vehicule(self):
        self._nv += 1
        self.vehicules.add(self._nv)
        return self._nv

    def nouveau_chauffeur(self):
        self._nc += 1
        self.chauffeurs.add(self._nc)
        self.affectations[self._nc] = None
        return self._nc

    def _controle(self, ident, connus, nom):
        if nom in self.en_panne:
            raise HTTPException(503, f"Service {nom} indisponible")
        if ident not in connus:
            raise HTTPException(404, f"{nom} {ident} introuvable")

    def verifier_vehicule(self, vehicule_id):
        self._controle(vehicule_id, self.vehicules, "Vehicule")

    def verifier_chauffeur(self, chauffeur_id):
        self._controle(chauffeur_id, self.chauffeurs, "Chauffeur")
    
    def obtenir_vehicule_affecte(self, chauffeur_id):
        self._controle(chauffeur_id, self.chauffeurs, "Chauffeur")
        return self.affectations.get(chauffeur_id)


@pytest.fixture(autouse=True)
def services(monkeypatch):
    monkeypatch.setenv("JWT_SECRET", "secret-jwt-tests-carburant-2026")
    monkeypatch.setenv("SERVICE_API_KEY", "cle-service-tests")
    faux = FauxServices()
    monkeypatch.setattr(clients, "verifier_vehicule", faux.verifier_vehicule)
    monkeypatch.setattr(clients, "verifier_chauffeur", faux.verifier_chauffeur)
    monkeypatch.setattr(clients, "obtenir_vehicule_affecte", faux.obtenir_vehicule_affecte)
    return faux


@pytest.fixture()
def admin_headers(monkeypatch):
    monkeypatch.setenv("JWT_SECRET", "secret-jwt-tests-carburant-2026")
    monkeypatch.setenv("SERVICE_API_KEY", "cle-service-tests")
    return {"Authorization": f"Bearer {auth.issue_token_admin()}"}
