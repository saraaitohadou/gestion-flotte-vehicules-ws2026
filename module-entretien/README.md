# Service Entretien - Gestion de flotte (Laravel 11+ / MySQL 8 / Docker)

Microservice REST/JSON responsable des entretiens (préventifs et correctifs), des échéances et des alertes.
Il dialogue avec le service **Véhicule** par API REST (statut + kilométrage).

## 1. Installation (une seule fois)

Depuis la racine du dépôt `gestion-flotte-vehicules-ws2026` (à côté de `module-chauffeur`) :

```powershell
# 1) Squelette Laravel (aucun PHP local nécessaire, via Docker)
docker run --rm -v "${PWD}:/app" composer create-project laravel/laravel module-entretien

# 2) Dézipper module-entretien.zip à la racine du dépôt EN ÉCRASANT les fichiers existants
#    (le zip contient le dossier module-entretien/)

# 3) Utiliser notre configuration d'environnement
cd module-entretien
Copy-Item .env.example .env -Force      # (Linux/macOS : cp -f .env.example .env)

# 4) Démarrer (avec le faux service Véhicule tant que le vrai n'est pas prêt)
docker compose --profile mock up -d --build
```

- API : http://localhost:8003/api  - Swagger : http://localhost:8003/docs
- MySQL : localhost:3309 (user `entretien` / mdp `secret`)
- Les migrations s'exécutent automatiquement au démarrage.
- Avec le vrai service Véhicule : `docker compose up -d` (réseau Docker partagé `flotte-net`, nom d'hôte `vehicule-service`, port 8001 - modifiable via `VEHICULE_SERVICE_URL`).

## 2. Postman

Importer `postman/Entretien.postman_collection.json`. Trois tokens (admin, gestionnaire, chauffeur du véhicule 1) sont déjà dans les variables ; la variable `token` (gestionnaire par défaut) est celle utilisée. Ils sont signés avec le `JWT_SECRET` de `.env.example` ; pour en générer d'autres :

```bash
docker compose exec entretien-app php artisan entretien:token gestionnaire
docker compose exec entretien-app php artisan entretien:token chauffeur --vehicule=1
```

## 3. Endpoints

| Méthode | URL | Rôles | Description |
|---|---|---|---|
| GET | /api/entretiens | tous (chauffeur : son véhicule) | liste filtrable + pagination |
| POST | /api/entretiens | Gestionnaire, Admin | crée (statut PLANIFIE) |
| GET | /api/entretiens/{id} | tous (chauffeur : son véhicule) | détail |
| PUT | /api/entretiens/{id} | Gestionnaire, Admin | modifie (PLANIFIE / EN_COURS) |
| PATCH | /api/entretiens/{id}/demarrer | Gestionnaire, Admin | PLANIFIE → EN_COURS, véhicule → EN_MAINTENANCE |
| PATCH | /api/entretiens/{id}/terminer | Gestionnaire, Admin | EN_COURS → TERMINE, km MAJ, véhicule → DISPONIBLE, échéance recalculée |
| PATCH | /api/entretiens/{id}/annuler | Gestionnaire, Admin | → ANNULE |
| DELETE | /api/entretiens/{id} | Admin | **archivage** (jamais de suppression physique) |
| GET | /api/vehicules/{id}/entretiens | tous (chauffeur : son véhicule) | historique complet (archives incluses) |
| GET | /api/alertes | tous (chauffeur : son véhicule) | alertes PROCHE / EN_RETARD |

Erreurs au format `{ "error": { "code", "message", "details" } }` (400/401/403/404/409/422/503).

## 4. Règles de gestion → code

| Règle du cahier des charges | Où |
|---|---|
| Champs obligatoires, type préventif/correctif | `StoreEntretienRequest` + `config/entretien.php` |
| Véhicule existant et actif, km ≥ dernier km connu, coût ≥ 0 | `EntretienService::creer / verifierKilometrage` |
| Statuts sans retour en arrière | `StatutEntretien::transitionsAutorisees` |
| EN_COURS ⇒ véhicule EN_MAINTENANCE, DISPONIBLE à la clôture | `EntretienService::demarrer / terminer / annuler` |
| Un seul entretien EN_COURS par véhicule | contrôle applicatif + index unique MySQL (colonne générée) |
| Échéance par périodicité ou km, recalculée à chaque clôture | `calculerProchaineEcheance` + `config/entretien.php` |
| Alerte à 15 j / 500 km puis « en retard » | `EcheanceService` (seuils dans `.env`) |
| CRON quotidien asynchrone | `routes/console.php` + conteneur `entretien-scheduler` |
| Archiver au lieu de supprimer | `EntretienService::archiver` (`archived_at`) |
| Rôles Admin / Gestionnaire / Chauffeur | middlewares `jwt` + `role`, `AuthUser` |
| Garde-fou si Véhicule injoignable | `VehiculeClient` → 503, aucune modification locale |

## 5. Contrat attendu du service Véhicule (à valider avec le responsable Véhicule)

- `GET /api/vehicules/{id}` → `{ "id", "statut", "kilometrage" }` (ou enveloppé dans `data`)
- `PATCH /api/vehicules/{id}/statut` ← `{ "statut": "EN_MAINTENANCE" | "DISPONIBLE" }`
- `PATCH /api/vehicules/{id}/kilometrage` ← `{ "kilometrage": 12345 }`
- Les appels portent un JWT de service (rôle ADMINISTRATEUR, 60 s) signé avec le `JWT_SECRET` partagé.

## 6. Tests

```bash
docker compose exec entretien-app php artisan test
```
Les tests utilisent SQLite en mémoire (jamais la base MySQL) et simulent le service Véhicule (`Http::fake`).

## 7. Git (une branche par fonctionnalité + Pull Request)

```bash
git checkout -b feature/service-entretien
git add module-entretien && git commit -m "feat(entretien): service Entretien Laravel"
git push -u origin feature/service-entretien   # puis ouvrir la Pull Request
```
