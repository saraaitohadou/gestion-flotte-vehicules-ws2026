# Service Carburant

Web service REST (FastAPI + MySQL) qui enregistre les pleins de carburant des
vehicules et suit couts et consommation. Il fait partie du projet « Gestion de
flotte de vehicules » (services Vehicule, Chauffeur, Entretien, Carburant).

## Lancer

Avec le docker-compose de la racine du depot (base MySQL commune a tous les services).

Sans Docker (developpement):

```bash
python -m pip install -r requirements.txt
uvicorn main:app --reload
```

Documentation interactive: http://127.0.0.1:8000/docs

## Interface web

Une interface web (HTML/CSS/JavaScript, sans installation) est servie par le
service lui-meme: **http://127.0.0.1:8000/ui/** (ou le port expose par Docker,
par exemple http://127.0.0.1:8003/ui/). Elle contient 5 ecrans: tableau de
bord, liste des pleins (filtres, pagination, modification, suppression),
nouveau plein, alertes de surconsommation et statistiques d'un vehicule. Elle
appelle l'API du meme service (pas de configuration). Les vehicules et
chauffeurs se saisissent par leur identifiant (la liste des vehicules appartient
au service Vehicule). Si un build React est present dans `frontend-dist/`, il est
servi ; sinon le service retombe sur `static/` (aucun crash au demarrage).

## Variables d'environnement

| Variable | Role | Defaut |
|---|---|---|
| `DATABASE_URL` | Surcharge explicite (optionnelle) de l'URL SQLAlchemy | construite depuis `DB_*` |
| `DB_HOST` / `DB_PORT` | Hote et port MySQL communs | `db-commune` / `3306` |
| `DB_NAME` | Base commune | `gestion_flotte` |
| `DB_USER` / `DB_PASSWORD` | Identifiants MySQL communs | `root` / `rootpassword` |
| `VEHICULE_SERVICE_URL` | Adresse du service Vehicule | `http://vehicule-service` |
| `CHAUFFEUR_SERVICE_URL` | Adresse du service Chauffeur | `http://chauffeur-service:8000` |
| `SERVICE_TIMEOUT` | Delai d'attente des appels (secondes) | `3` |
| `SEUIL_SURCONSOMMATION` | Seuil d'alerte de surconsommation | `0.30` (30 %) |

La table `carburants` est creee par **Alembic** (`alembic upgrade head`, lance au
demarrage avec plusieurs tentatives si MySQL n'est pas encore pret). Il n'y a plus
de `Base.metadata.create_all()` en production. Alembic enregistre la revision dans
`alembic_version` ; la migration est idempotente et ne contient aucun `DROP TABLE`.

## Tests

```bash
python -m pytest -q
python -m pytest test_unitaires_carburant.py --junitxml=rapport_tests_unitaires.xml
```

Les tests utilisent SQLite en memoire et simulent les services Vehicule et
Chauffeur (voir `conftest.py`): ni Docker ni MySQL ne sont necessaires.

## Structure

| Fichier | Role |
|---|---|
| `static/` | Interface web (`index.html`, `style.css`, `app.js`) |
| `main.py` | Cree l'application, lance `alembic upgrade head`, branche le routeur |
| `database.py` | Connexion a la base (`DB_*` puis surcharge `DATABASE_URL`) |
| `alembic.ini`, `alembic/` | Migrations versionnees de la table `carburants` |
| `models.py` | Table `Carburant` (`vehicule_id` et `chauffeur_id` sont de simples identifiants) |
| `schemas.py` | Validation des entrees/sorties (Pydantic) |
| `clients.py` | Appels HTTP vers les services Vehicule et Chauffeur |
| `anomalies.py` | Calcul de la consommation et alerte de surconsommation |
| `routers/carburant.py` | Les endpoints du module |
| `conftest.py` | Faux services Vehicule / Chauffeur pour les tests |

## Endpoints

| Methode | URL | Description | Codes |
|---|---|---|---|
| POST | `/carburants` | Enregistrer un plein | 201, 400, 404, 422, 503 |
| GET | `/carburants` | Lister (filtres: `vehicule_id`, `chauffeur_id`, `type_carburant`, `date_debut`, `date_fin`, `skip`, `limit`) | 200 |
| GET | `/carburants/{id}` | Lire un plein | 200, 404 |
| PATCH | `/carburants/{id}` | Modifier (montant et alertes recalcules) | 200, 400, 404, 422, 503 |
| DELETE | `/carburants/{id}` | Supprimer | 204, 404 |
| GET | `/carburants/anomalies` | Pleins en surconsommation (filtres: `vehicule_id`, `date_debut`, `date_fin`) | 200 |
| GET | `/carburants/stats/vehicules/{id}` | Cout total, distance, consommation L/100 km, cout/km | 200, 404, 503 |

## Communication avec les autres services

- Avant d'enregistrer ou de modifier un plein, le service verifie par HTTP que
  le vehicule existe (`GET /vehicules/{id}`) et, s'il est fourni, que le
  chauffeur existe (`GET /api/chauffeurs/{id}`).
- Introuvable: **404**. Service injoignable ou en erreur: **503**, rien n'est enregistre.
- Les chemins sont definis en haut de `clients.py`.

## Regles metier

- `montant_total = quantite_litres x prix_litre` (calcule par le serveur, arrondi a 2 decimales).
- RG-01: le kilometrage doit etre **strictement croissant** dans le temps pour un meme
  vehicule (comparaison avec les pleins precedent et suivant). Sinon **400**.
- Consommation = litres / distance depuis le plein precedent x 100 (pleins complets).
- Les donnees invalides (quantite <= 0, type inconnu...) donnent **422**.

## Alerte de surconsommation

Chaque plein recoit automatiquement `consommation_l_100km`, `anomalie` et
`message_anomalie`. Un plein est marque anomalie si sa consommation depasse de
plus de **30 %** la moyenne des consommations precedentes du vehicule (au moins
2 consommations connues). Exemple: un vehicule a 7 L/100 km qui passe a 14 -> alerte,
visible via `GET /carburants/anomalies`.

Le seuil se change sans modifier le code: `SEUIL_SURCONSOMMATION=0.25`.
Le calcul est refait pour tout le vehicule a chaque ajout, modification ou
suppression, donc corriger une erreur de saisie retire l'alerte.

## Pas encore developpe

- RG-02 (alerte capacite du reservoir): necessite ce champ dans le service Vehicule.
- RG-03 (anti-doublon 15 minutes): necessite une date **et heure** sur le plein.
- RG-06 et RG-07 (droits d'acces, lecture seule du chauffeur): necessitent le JWT partage.
