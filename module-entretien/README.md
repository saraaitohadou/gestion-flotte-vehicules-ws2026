# Service Entretien

Microservice de gestion des entretiens de la flotte (Laravel, MySQL, JWT).

## Démarrage

```powershell
docker network create flotte-net   # une seule fois (ignorer "already exists")
docker compose up -d --build
docker compose exec entretien-app php artisan migrate --force
```

| Composant | URL / port |
|---|---|
| API entretien | http://localhost:8001/api |
| Mock véhicule | http://localhost:8002 |
| MySQL | localhost:3307 |
| Contrat OpenAPI | `public/openapi.yaml` |

Santé : `GET http://localhost:8001/api/health`

## Authentification

JWT (Bearer, HS256), secret partagé `JWT_SECRET`. Rôles :

- **ADMINISTRATEUR** : tout, dont l'archivage (DELETE).
- **GESTIONNAIRE** : créer, modifier, démarrer, terminer, annuler.
- **CHAUFFEUR** : lecture des entretiens de son véhicule uniquement.

Générer un token de développement :

```powershell
docker compose exec -T entretien-app php artisan entretien:token gestionnaire
docker compose exec -T entretien-app php artisan entretien:token administrateur
```

## Règles métier

- Cycle de vie : PLANIFIE → EN_COURS → TERMINE ; PLANIFIE ou EN_COURS → ANNULE. Aucun retour en arrière.
- Démarrer : le véhicule passe EN_MAINTENANCE. Un seul entretien EN_COURS par véhicule.
- Terminer : le véhicule repasse DISPONIBLE, le kilométrage est mis à jour, `date_fin` vaut now() par défaut.
- Échéance (préventif) : calculée depuis `config/entretien.php` (ex. VIDANGE = 6 mois / 10 000 km). Les alertes ouvertes du véhicule et de la catégorie sont résolues.
- Annuler un entretien EN_COURS : le véhicule repasse DISPONIBLE.
- DELETE = archivage (`archive_at`), jamais de suppression physique. ADMIN uniquement, sur TERMINE ou ANNULE seulement.
- Kilométrage saisi >= kilométrage du véhicule (`KILOMETRAGE_INVALIDE`).
- Format d'erreur : `{"error": {"code", "message", "details"}}`. Succès : `{"data": ...}`.

## Alertes (CRON)

Commande quotidienne : calcule les échéances des entretiens préventifs et génère les alertes (PROCHE / EN_RETARD).

```powershell
docker compose exec entretien-app php artisan entretiens:verifier-echeances
```

Seuils : `ALERTE_JOURS_AVANT` (15 jours) et `ALERTE_KM_AVANT` (500 km). Consultation : `GET /api/alertes`.

## Tests

```powershell
docker compose exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: entretien-app php artisan test
docker compose exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: entretien-app php artisan test --testsuite=Unit
```

Attendu : 25 passed (10 unitaires + 15 Feature). Les tests Feature simulent le service véhicule (`Http::fake`).

## Postman

Collection : `postman/collections/Module Entretien/` (variables `base_url`, `token`, `entretien_id`). Mettre le token gestionnaire ou administrateur dans `token`.

## Intégration avec le service Véhicule

Par défaut, `VEHICULE_SERVICE_URL=http://vehicule-mock:8002` : un mock Node (`docker/mock-vehicule`) simule le service Véhicule (véhicule 1 = DISPONIBLE à 45 000 km, 3 = HORS_SERVICE, 4 = ARCHIVE, 5 = DISPONIBLE (10 000 km), 99 = introuvable).

Le service est aussi attaché au réseau Docker externe `flotte-net`. Pour utiliser le vrai service Véhicule :

1. Récupérer le nom de service/container et le port du service Véhicule.
2. Modifier `VEHICULE_SERVICE_URL` dans `docker-compose.yml` et `.env.example` (ex. `http://vehicule-app:8000`).
3. Le service doit exposer :
   - `GET /api/vehicules/{id}` → `statut`, `kilometrage`
   - `PATCH /api/vehicules/{id}/statut`
   - `PATCH /api/vehicules/{id}/kilometrage`

   Si les routes ou champs diffèrent, adapter `app/Services/VehiculeClient.php`.

Variables utiles : `VEHICULE_SERVICE_TIMEOUT` (3 s).