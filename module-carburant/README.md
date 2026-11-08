# Gestion de flotte - module Carburant

Web service REST (FastAPI + SQLite) pour enregistrer les pleins de carburant
des vehicules et suivre couts et consommation.

## Lancer

```bash
pip install -r requirements.txt
uvicorn main:app --reload
```

Documentation interactive: http://localhost:8000/docs

## Tests

```bash
pytest -q
```

## Structure

| Fichier | Role |
|---|---|
| `main.py` | Cree l'application et branche les routeurs |
| `database.py` | Connexion SQLite (variable `DATABASE_URL` pour changer de base) |
| `models.py` | Tables `Vehicule`, `Chauffeur`, `Carburant` |
| `schemas.py` | Validation des entrees/sorties (Pydantic) |
| `routers/carburant.py` | **Le module carburant** (CRUD + statistiques) |
| `routers/stubs.py` | Vehicule/Chauffeur minimaux pour tester. A supprimer quand les autres modules arrivent |

## Endpoints Carburant

| Methode | URL | Description |
|---|---|---|
| POST | `/carburants` | Enregistrer un plein |
| GET | `/carburants` | Lister (filtres: `vehicule_id`, `chauffeur_id`, `type_carburant`, `date_debut`, `date_fin`, `skip`, `limit`) |
| GET | `/carburants/{id}` | Lire un plein |
| PATCH | `/carburants/{id}` | Modifier (le montant est recalcule) |
| DELETE | `/carburants/{id}` | Supprimer |
| GET | `/carburants/anomalies` | Pleins en surconsommation (filtres: `vehicule_id`, `date_debut`, `date_fin`) |
| GET | `/carburants/stats/vehicules/{id}` | Cout total, distance, consommation L/100 km, cout/km |

## Regles metier

- `montant_total = quantite_litres x prix_litre` (calcule par le serveur).
- Le vehicule doit exister, le chauffeur est optionnel mais doit exister s'il est donne.
- Le kilometrage ne peut pas reculer: il doit rester coherent avec les pleins
  precedents et suivants du meme vehicule.
- Consommation moyenne = litres des pleins suivants le premier / distance parcourue x 100.

## Alerte de surconsommation

Chaque plein recoit automatiquement `consommation_l_100km`, `anomalie` et
`message_anomalie`. Un plein est marque anomalie si sa consommation depasse
de plus de **30 %** la moyenne des consommations precedentes du vehicule
(au moins 2 consommations connues). Exemple: un vehicule a 7 L/100 km qui
passe a 14 -> alerte, visible via `GET /carburants/anomalies`.

Le seuil se change sans modifier le code: `SEUIL_SURCONSOMMATION=0.25 uvicorn main:app`.
Le calcul est refait pour tout le vehicule a chaque ajout, modification ou
suppression, donc corriger une erreur de saisie retire l'alerte.

## Exemple

```bash
curl -X POST localhost:8000/vehicules -H "Content-Type: application/json" \
  -d '{"immatriculation":"12345-A-6","marque":"Dacia","modele":"Logan"}'

curl -X POST localhost:8000/carburants -H "Content-Type: application/json" \
  -d '{"vehicule_id":1,"date_plein":"2026-10-17","quantite_litres":40,
       "prix_litre":11.5,"kilometrage":10000,"type_carburant":"diesel"}'
```

## Integration avec l'equipe

Les tables `vehicules` et `chauffeurs` de `models.py` sont minimales. Si vos
collegues ont deja leurs modeles, gardez la cle etrangere `vehicule_id` /
`chauffeur_id` de `Carburant` et remplacez simplement ces deux classes.
