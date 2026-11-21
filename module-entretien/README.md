# Service Entretien

Microservice de gestion des entretiens de la flotte (Laravel 11, MySQL 8).

## Demarrage

### Mode mock (par defaut, service seul)

```powershell
docker network create flotte-net   # une seule fois (ignorer "already exists")
docker compose up -d --build
docker compose exec entretien-app php artisan migrate --force
```

| Composant | URL / port |
|---|---|
| API entretien | http://localhost:8004/api |
| Mock vehicule | http://localhost:8002 |
| Mock chauffeur | http://localhost:8003 |
| MySQL | localhost:3307 |
| Contrat OpenAPI | `public/openapi.yaml` |

Sante : `GET http://localhost:8004/api/health`

### Mode reel (services Vehicule et Chauffeur)

Modifier `VEHICULE_SERVICE_URL` et `CHAUFFEUR_SERVICE_URL` dans `docker-compose.yml` et `.env.example`
(ex. `http://vehicule-app:8000`, `http://chauffeur-app:8000`), puis `docker compose up -d`.
Le service est attache au reseau externe `flotte-net`. Si les routes ou champs different, adapter
`app/Services/VehiculeClient.php` et `app/Services/ChauffeurClient.php`.

Mocks : vehicule 1/2/5 = DISPONIBLE, 3 = HORS_SERVICE, 4 = ARCHIVE ; chauffeur 1 = EN_MISSION,
2 = DISPONIBLE, 3 = EN_CONGE, 4 = INACTIF.

## Regles metier

- Cycle de vie : PLANIFIE -> EN_COURS -> TERMINE ; PLANIFIE ou EN_COURS -> ANNULE. Aucun retour en arriere.
- Demarrer : le vehicule passe EN_MAINTENANCE. Un seul entretien EN_COURS par vehicule.
- Terminer : le vehicule repasse DISPONIBLE, le kilometrage est mis a jour.
- Echeance (preventif) : calculee depuis `config/entretien.php` (ex. VIDANGE = 6 mois / 10 000 km).
- Kilometrage saisi >= kilometrage du vehicule (`KILOMETRAGE_INVALIDE`).
- Format d'erreur : `{"error": {"code", "message", "details"}}`. Succes : `{"data": ...}`.

## Declaration de panne

- `POST /api/declarations-panne` : un chauffeur declare une panne (categorie PANNE ou REPARATION).
  Vehicule HORS_SERVICE/ARCHIVE refuse ; EN_MISSION accepte. Chauffeur EN_CONGE/INACTIF refuse.
  Si le service Chauffeur est injoignable : mode degrade (`chauffeur_verifie = false`).
- `GET /api/declarations-panne`, `GET /api/declarations-panne/{id}`
- `PATCH /api/declarations-panne/{id}/prendre-en-charge` : cree un entretien CORRECTIF PLANIFIE, statut TRAITEE.
- `PATCH /api/declarations-panne/{id}/rejeter` : statut REJETEE.
- Une declaration deja traitee renvoie 422 `DECLARATION_DEJA_TRAITEE`.
## Scheduler et alertes

Le conteneur `entretien-scheduler` lance `php artisan schedule:work`. La commande
`entretiens:verifier-echeances` s'execute **chaque jour a 02:00** et genere les alertes (PROCHE / EN_RETARD).

```powershell
docker compose exec entretien-app php artisan entretiens:verifier-echeances
```

Seuils : `ALERTE_JOURS_AVANT` (15 jours) et `ALERTE_KM_AVANT` (500 km). Consultation : `GET /api/alertes`.

## Archivage

`DELETE /api/entretiens/{id}` = archivage logique (`archive_at`), jamais de suppression physique.
Reserve a l'ADMIN, uniquement sur un entretien TERMINE ou ANNULE. Historique complet d'un vehicule
(archives incluses) : `GET /api/vehicules/{id}/entretiens`.

## Tests

```powershell
docker compose run --rm --no-deps -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: entretien-app php artisan test
```

Attendu : **70 passed**. Les tests simulent les services externes (`Http::fake`).

## Authentification et roles

Authentification par JWT (HS256). Le service **verifie** le token ; il ne l'emet pas
(le token est emis par le service Chauffeur, `POST /api/auth/login`, meme `JWT_SECRET`).
Header : `Authorization: Bearer <token>`.
Claims : `sub` (id), `role`, `vehicule_id` (optionnel), `exp`.
`JWT_SECRET` : 16 caracteres minimum, identique dans les deux services. Un secret absent ou par defaut est refuse (500 `CONFIG_MANQUANTE`).
Erreurs : 401 `NON_AUTHENTIFIE` / `TOKEN_INVALIDE` / `TOKEN_EXPIRE`, 403 `ACCES_INTERDIT`.

| Action | Roles autorises |
|---|---|
| Lecture entretiens, historique, declarations | ADMINISTRATEUR, GESTIONNAIRE, CHAUFFEUR |
| Alertes, creer/modifier/demarrer/terminer/annuler un entretien | ADMINISTRATEUR, GESTIONNAIRE |
| Prendre en charge / rejeter une declaration | ADMINISTRATEUR, GESTIONNAIRE |
| Archiver (DELETE) | ADMINISTRATEUR |
| Declarer une panne | CHAUFFEUR, ADMINISTRATEUR |

`GET /api/health` reste public.

Limite actuelle : le login du service Chauffeur n'emet que des tokens ADMINISTRATEUR.
Les roles GESTIONNAIRE et CHAUFFEUR sont verifies par Entretien et couverts par les tests.
## Postman

Collection : `postman/collections/Module Entretien/` (variables `base_url`, `entretien_id`).