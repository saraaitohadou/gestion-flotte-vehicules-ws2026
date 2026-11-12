# Service Véhicule

Web service REST (PHP 8.4 + MySQL 8) qui gère les véhicules de la flotte : création, consultation, modification, suppression, statut, disponibilité et kilométrage. Il fait partie du projet « Gestion de flotte de véhicules » (services Véhicule, Chauffeur, Entretien, Carburant).

## Lancer le service

Prérequis : Docker Desktop.

```
copy .env.example .env
docker compose up -d --build
```

- API : http://127.0.0.1:8090
- Base MySQL (depuis le PC) : port 3308
- Arrêter : `docker compose down` (ajouter `-v` pour effacer les données)

## Variables d'environnement

| Variable | Rôle | Valeur dans Docker |
|---|---|---|
| DB_HOST | Hôte de la base | vehicule-db |
| DB_PORT | Port de la base | 3306 |
| DB_NAME | Nom de la base | vehicule_db |
| DB_USER | Utilisateur | root |
| DB_PASS | Mot de passe | défini dans `.env` |

## Modèle de données

| Champ | Type | Description |
|---|---|---|
| id | entier | Identifiant généré |
| immatriculation | texte | Plaque, unique |
| marque | texte | Constructeur |
| modele | texte | Modèle |
| annee | entier | Année de mise en circulation |
| kilometrage | nombre | Distance parcourue, ne diminue jamais |
| typeCarburant | texte | Diesel, Essence, Hybride, Électrique |
| statut | texte | DISPONIBLE, EN_MISSION, EN_MAINTENANCE, HORS_SERVICE |

## Routes

| Méthode | Route | Description | Codes |
|---|---|---|---|
| GET | /vehicules | Lister les véhicules | 200 |
| POST | /vehicules | Créer un véhicule (statut DISPONIBLE) | 201, 409, 422 |
| GET | /vehicules/{id} | Consulter un véhicule | 200, 404 |
| PUT | /vehicules/{id} | Modifier un véhicule | 200, 404, 409, 422 |
| DELETE | /vehicules/{id} | Supprimer un véhicule | 204, 404, 409 |
| PATCH | /vehicules/{id}/statut | Changer le statut | 200, 404, 409, 422 |
| GET | /vehicules/{id}/disponibilite | Savoir si le véhicule est disponible | 200, 404 |
| PATCH | /vehicules/{id}/kilometrage | Mettre à jour le kilométrage | 200, 404, 422 |

Signification des codes d'erreur : **404** véhicule introuvable, **409** conflit avec une règle (immatriculation existante, transition de statut impossible, véhicule en mission), **422** données invalides.
Les erreurs renvoient `{"erreur": "message"}`.

## Exemples

Créer un véhicule :
```
POST /vehicules
{"immatriculation":"TEST-1-A","marque":"Peugeot","modele":"208","annee":2022,"kilometrage":1000,"typeCarburant":"Essence"}
```

Changer le statut :
```
PATCH /vehicules/1/statut
{"statut":"EN_MISSION"}
```

Vérifier la disponibilité (réponse) :
```
GET /vehicules/1/disponibilite
{"id":1,"disponible":true}
```

Mettre à jour le kilométrage :
```
PATCH /vehicules/1/kilometrage
{"kilometrage":45650}
```

## Transitions de statut autorisées

| De | Vers |
|---|---|
| DISPONIBLE | EN_MISSION, EN_MAINTENANCE, HORS_SERVICE |
| EN_MISSION | DISPONIBLE |
| EN_MAINTENANCE | DISPONIBLE, HORS_SERVICE |
| HORS_SERVICE | DISPONIBLE |

## Utilisation par les autres services

- **Chauffeur** : `GET .../disponibilite`, puis `PATCH .../statut` (EN_MISSION, puis DISPONIBLE).
- **Entretien** : `GET /vehicules/{id}`, puis `PATCH .../statut` (EN_MAINTENANCE, puis DISPONIBLE).
- **Carburant** : `GET /vehicules/{id}`, puis `PATCH .../kilometrage`.

Depuis un autre conteneur du même réseau Docker, l'adresse du service est `http://vehicule-service`.

## Structure

```
vehicule-service/
├── public/index.php        point d'entrée et routes
├── src/Controller/         lien HTTP et service métier
├── src/Service/            règles de gestion
├── src/Repository/         accès à la base
├── src/Models/             Vehicule, StatutVehicule
├── src/Exceptions/         erreurs métier (404, 409, 422)
├── db/init.sql             création de la table et données de test
├── Dockerfile
└── docker-compose.yml
```

## Tests

Avec le service lancé : `powershell -ExecutionPolicy Bypass -File .\test_api.ps1` (15 cas de succès et d'erreur).