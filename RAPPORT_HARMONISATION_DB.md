# Rapport d’harmonisation de la base de données commune

**Date :** 2026-11-24  
**Périmètre :** `module-vehicule`, `module-chauffeur`, `module-carburant`, `module-entretien`, `docker-compose.yml`, scripts `db/`, configuration `.env`.  
**Objectif :** faire converger les quatre services vers l’unique base MySQL `gestion_flotte`, sans perte de données, en conservant la propriété des tables et les appels inter-services REST.

---

## 1. Audit avant modification

### Base live

Une seule base existe dans le conteneur `db-commune` : **`gestion_flotte`**. Aucune base `vehicule_db`, `flotte_db`, `entretien_db` ou `carburant_db` n’est montée dans la stack active.

Volumes inutilisés mais **non supprimés** (sécurité) :

- `module-vehicule_vehicule_db_data`
- `module-entretien_entretien_data`
- `module-entretien-final_entretien-db-data`

### Sauvegarde avant tout changement de schéma

- Fichier : `db/backups/gestion_flotte_20261124_123438.sql`
- Taille : 45 626 octets
- SHA256 : `735ADADBF06DDF7028EB1906CD592AF78375F1CF0016B38E8F9E7FAC0836F901`
- Méthode : `mysqldump --single-transaction --routines --triggers --databases gestion_flotte`
- Restauration : **non nécessaire**, aucune donnée n’a été écrasée.

### État de référence (avant changement)

| Table | Lignes |
|---|---:|
| `vehicules` | 3 |
| `chauffeurs` | 4 |
| `entretiens` | 18 |
| `entretien_historique` | 40 |
| `declarations_panne` | 5 |
| `integrations_outbox` | 15 |
| `integrations_idempotence` | 18 |

Les 8 migrations Laravel Entretien étaient déjà appliquées dans `migrations`.

### Incohérences detectées

1. **Véhicule** : `module-vehicule/docker-compose.yml` démarrait un MySQL `vehicule_db` avec volume `vehicule_db_data` ; `db/init.sql` créait `vehicule_db` ; `src/Database.php` avait pour défaut `vehicule_db` / port `3307` ; README et `.env` documentaient `vehicule_db`.
2. **Chauffeur** : `database.py` avait pour défaut `flotte_db` sur `db:3306` ; la table `chauffeurs` était créée par `create_all()` sans migration versionnée traçable.
3. **Carburant** : `database.py` avait pour défaut `flotte_db` sur `localhost:3306` ; aucune table `carburants` n’existait ; `create_all()` était utilisé en production ; le service était en **crash-loop** (`Directory '/app/frontend-dist' does not exist`).
4. **Entretien** : le Compose autonome utilisait `DB_HOST_PORT=3306` et `DB_PASSWORD=changez_moi`, divergences avec la stack racine (`3307` / `rootpassword`). Aucune base dédiée n’était en revanche utilisée.
5. **`db/init/*.sql`** : DDL dupliqué par rapport aux futures migrations, sans mécanisme de traçabilité pour Véhicule/Chauffeur.
6. **Concepts métier** : aucune table physique `affectations` ni `echeances` n’existe. C’est volontaire : les affectations sont `chauffeurs.vehicule_id` et les échéances sont calculées depuis `entretiens` puis matérialisées dans `alertes`. Créer des tables vides aurait introduit une seconde source de vérité.

---

## 2. Corrections appliquées

### 2.1 Docker Compose et `.env`

| Fichier | Correction |
|---|---|
| `docker-compose.yml` | `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` identiques pour Chauffeur et Carburant ; `DB_DATABASE` aligné pour Entretien ; suppression des `DATABASE_URL` en dur ; montage des migrations déplacé hors de `/docker-entrypoint-initdb.d` (lecture seule) vers `/docker-entrypoint-migrations/{vehicule,chauffeur}`. |
| `module-vehicule/docker-compose.yml` | Suppression du service `vehicule-db`, du volume `vehicule_db_data` et du port `3308`. Le service utilise `db-commune` / `gestion_flotte` via le réseau externe `flotte_flotte-network`. |
| `module-entretien/docker-compose.yml` | Défauts autonomes alignés sur `DB_HOST_PORT=3307` et `DB_ROOT_PASSWORD=rootpassword`. Aucune `entretien_db`. |
| `.env`, `.env.example` | Commentaires et valeurs alignés sur les quatre services ; `ENTRETIEN_DB_NAME=gestion_flotte`. |
| `module-vehicule/.env`, `.env.example` | Plus de `vehicule_db` : `DB_HOST=db-commune`, `DB_NAME=gestion_flotte`, secrets alignés. |
| `module-entretien/.env`, `.env.example` | Déjà sur `gestion_flotte` ; documentation précisée qu’aucune `entretien_db` ne doit être créée. |
| `module-vehicule/src/Database.php` | Défaut `gestion_flotte` / `3306` au lieu de `vehicule_db` / `3307`. |
| `module-chauffeur/database.py`, `module-carburant/database.py` | URL construite depuis `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` ; `DATABASE_URL` conservé comme surcharge explicite. Échappement des identifiants via `quote_plus`. |
| `module-carburant/main.py` | `alembic upgrade head` au démarrage au lieu de `create_all()` ; repli de l’UI vers `static/` si `frontend-dist/` est absent (arrêt du crash-loop). |
| `module-chauffeur/main.py` | Appel à `appliquer_migrations()` au démarrage au lieu de `create_all()` en MySQL ; repli `create_all()` conservé uniquement pour les tests SQLite. |

`docker compose config --quiet` passe pour la stack racine, `module-vehicule` et `module-entretien`.

### 2.2 Migrations créées et appliquées

| Service | Mécanisme | Source de vérité | État appliqué |
|---|---|---|---|
| Véhicule | SQL versionné | `db/migrations/001_create_vehicules.sql` | Réappliqué manuellement : **aucun changement de schéma ni de données** (`CREATE TABLE IF NOT EXISTS`). |
| Chauffeur | SQL versionné | `module-chauffeur/db/migrations/001_create_chauffeurs.sql` | Appliqué au démarrage ; trace dans `gestion_flotte.migrations_sql` : `001_create_chauffeurs.sql`. Réexécution idempotente vérifiée. |
| Carburant | Alembic | `module-carburant/alembic/versions/0001_create_carburants.py` | `alembic current` = `0001_create_carburants (head)` ; trace dans `alembic_version`. Le `downgrade()` est volontairement neutre (aucun `DROP TABLE`). |
| Entretien | Laravel Migrations | `module-entretien/database/migrations/` | Inchangé ; 8 lignes dans `migrations`. |

`db/init/01-vehicule.sql` et `db/init/03-chauffeurs.sql` ne dupliquent plus le DDL : ils `SOURCE` les migrations versionnées et utilisent `INSERT IGNORE` pour les données de démonstration. `module-vehicule/db/init.sql` est neutralisé (fichier legacy sans instruction SQL).

La stratégie complète est documentée dans `db/migrations/README.md`.

**Aucune opération destructive n’a été utilisée** : pas de `DROP DATABASE`, `DROP TABLE`, `migrate:fresh`, suppression de volume, `docker compose down -v` ni écrasement de données.

### 2.3 Découplage applicatif préservé

Les relations inter-services restent exclusivement REST :

- Carburant vérifie véhicule et chauffeur par HTTP avant d’enregistrer un plein ;
- Entretien consulte Véhicule et Chauffeur via ses clients HTTP/proxies ;
- Chauffeur appelle Entretien avec un JWT de service de courte durée ;
- la saga/outbox `integrations_outbox` synchronise les statuts de véhicules sans écriture directe dans les tables des autres services.

Le partage du schéma MySQL ne court-circuite aucune règle métier.

---

## 3. Incidents rencontrés pendant l’intégration et résolutions

| Incident | Cause | Résolution |
|---|---|---|
| `HTTP 500 Data too long for column 'immatriculation'` | Suffixe de plaque à 12 caractères (22 > `VARCHAR(20)`). | Suffixe réduit à `yyMMddHHmm` dans `postman/harmonisation-db.ps1`. |
| Véhicule #2 bloqué `EN_MAINTENANCE` (62 500 km) | Entretien de test **#23** créé à 11:58 avant la sauvegarde, laissé `EN_COURS` ; outbox #17 `APPLIQUE` avait mis le véhicule en maintenance. | **Uniquement via REST** : `PATCH /api/entretiens/23/annuler` puis `DELETE /api/entretiens/23` (archivage logique). Retour à `DISPONIBLE` / 62 500 km vérifié. Aucune écriture métier directe en MySQL. |
| Entretien #24 laissé `PLANIFIE` par le run interrompu | La suppression直接 d’un entretien `PLANIFIE` est interdite par la règle métier. | `PATCH .../annuler` puis `DELETE ...` via REST. Le script de test a été corrigé pour nettoyer tout entretien resté `PLANIFIE`/`EN_COURS`. |
| `HTTP 409 CHAUFFEUR_INACTIF_EXISTANT` | CIN dérivé de la date réutilisé après suppression logique lors d’un run précédent. | Génération d’un CIN `ZZ` + 6 chiffres tirés au sort et vérifiés comme inutilisés. |
| Assertion d’archivage erronée | La colonne réelle est `archive_at` ; le champ JSON `archive` est calculé. | Assertion SQL corrigée : `IF(archive_at IS NOT NULL,'1','0')`. |

Aucun de ces correctifs ne modifie une règle métier ou un contrat d’API : ils utilisent les transitions et endpoints existants (`annuler`, `terminer`, `DELETE` d’archivage).

---

## 4. Tests réellement exécutés

### Validation statique et configuration

| Commande | Résultat |
|---|---|
| `python -m py_compile` sur 7 fichiers Python modifiés | OK |
| `docker compose config --quiet` (racine) | OK |
| `docker compose config --quiet` (`module-vehicule`) | OK |
| `docker compose config --quiet` (`module-entretien`) | OK |
| `docker compose exec -T vehicule-service php -l /app/src/Database.php` | OK |

### Suites unitaires / fonctionnelles

| Suite | Résultat |
|---|---|
| Chauffeur (`docker compose exec -T chauffeur-service python -m pytest -q`) | **70 passed** |
| Carburant (`docker compose exec -T carburant-service python -m pytest -q`) | **55 passed, 4 skipped** |
| Entretien (`docker compose exec -T entretien-app php artisan test`) | **136 passed (392 assertions)** |

### Intégration REST de bout en bout

Script : `postman/harmonisation-db.ps1`  
Preuve : `postman/evidence/harmonisation-db.json`  
Résultat : **OK — 6 scénarios vérifiés, données originales préservées.**

| Scénario vérifié | Preuve |
|---|---|
| Véhicule créé par Véhicule puis visible depuis Chauffeur | `GET /api/vehicules-disponibles` → 200, `present=1` |
| Véhicule visible depuis Entretien | `GET /api/vehicules/{id}` via le proxy Entretien → 200 |
| Véhicule validé par Carburant via REST | `POST /carburants` → 201 (refus 404 si le véhicule était inconnu) |
| Chauffeur créé par Chauffeur puis visible depuis Entretien | `GET /api/chauffeurs` via Entretien → 200, `present=1` |
| Chauffeur validé par Carburant via REST | `POST /carburants` avec `chauffeur_id` → 201 |
| Plein visible dans la base commune | `gestion_flotte.carburants` → `2|7|7|1200` |
| Entretien stocké dans la base commune | `gestion_flotte.entretiens` → `25|PLANIFIE|62500` |
| Cohérence statut / kilométrage | #2 `DISPONIBLE` → `EN_MAINTENANCE` → `DISPONIBLE`, toujours **62 500 km** ; #3 inchangé `EN_MAINTENANCE` / 98 500 km |
| Nettoyage sans perte | plein supprimé via API Carburant, chauffeur test `INACTIF`, véhicule test supprimé via API Véhicule, entretien test archivé (ligne conservée) |

### État final des conteneurs

`carburant-service`, `chauffeur-service`, `vehicule-service`, `db-commune (healthy)`, `entretien-app (healthy)`, `entretien-scheduler (healthy)`, `phpmyadmin`, `chauffeur-mock` : tous **Up**.

---

## 5. Préservation des données

| Table | Avant harmonisation | Après |
|---|---:|---:|
| `vehicules` | 3 | 3 |
| `chauffeurs` | 4 | 6 |
| `carburants` | 0 | 0 |
| `entretiens` | 18 | 20 |
| `entretien_historique` | 40 | 46 |
| `declarations_panne` | 5 | 5 |
| `integrations_outbox` | 15 | 18 |
| `integrations_idempotence` | 18 | 21 |

Précisions :

- Les **4 chauffeurs d’origine** et les **18 entretiens d’origine** sont toujours présents. Les lignes supplémentaires sont des résidus de tests : chauffeurs **inactifs** (#6, #7) et entretiens **archivés** (#23, #24, #25). Aucune suppression logique d’une donnée métier d’origine n’a été faite.
- Le véhicule de test et le plein de test ont été supprimés physiquement **par leurs propres API**, pas par SQL.
- `entretien_historique`, `integrations_outbox` et `integrations_idempotence` ont grandi uniquement à cause des traces des transitions REST exécutées : aucune ligne existante n’a été modifiée ou retirée.
- Véhicule #2 (Clio) : **`DISPONIBLE`, 62 500 km**. Véhicule #3 (Hilux) : **inchangé `EN_MAINTENANCE`, 98 500 km**.
- Le volume `flotte_db_commune_data` n’a jamais été supprimé ni réinitialisé.

---

## 6. Fichiers modifiés

### Suivis par Git (modifiés)

- `.env.example`
- `README.md`
- `db/init/01-vehicule.sql`
- `db/init/03-chauffeurs.sql`
- `docker-compose.yml`
- `module-carburant/README.md`
- `module-carburant/database.py`
- `module-carburant/main.py`
- `module-carburant/requirements.txt`
- `module-chauffeur/database.py`
- `module-chauffeur/main.py`
- `module-chauffeur/requirements.txt`
- `module-entretien/README.md`
- `module-entretien/docker-compose.yml`
- `module-vehicule/.env.example`
- `module-vehicule/README.md`
- `module-vehicule/db/init.sql`
- `module-vehicule/docker-compose.yml`
- `module-vehicule/src/Database.php`

### Nouveaux fichiers

- `db/backups/gestion_flotte_20261124_123438.sql`
- `db/migrations/001_create_vehicules.sql`
- `db/migrations/README.md`
- `module-chauffeur/db/migrations/001_create_chauffeurs.sql`
- `module-chauffeur/migrations.py`
- `module-carburant/alembic.ini`
- `module-carburant/alembic/env.py`
- `module-carburant/alembic/versions/0001_create_carburants.py`
- `postman/harmonisation-db.ps1`
- `postman/evidence/harmonisation-db.json`
- `RAPPORT_HARMONISATION_DB.md` (ce fichier)

### Fichiers locaux ignorés par Git mais corrigés

- `.env`
- `module-vehicule/.env`

`module-entretien/docker-compose.real.yml` et les migrations Laravel existantes n’ont pas été modifiés : ils étaient déjà alignés.

---

## 7. Contrôle Git final

Aucun `git commit`, `push`, `pull`, `fetch`, changement de branche ni écrasement de modification locale n’a été effectué. Toutes les modifications restent **non commitées** dans l’espace de travail.
