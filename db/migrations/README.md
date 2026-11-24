# Migrations versionnées de la base commune `gestion_flotte`

Objectif : une seule source de vérité par table, sans recréation concurrente et sans
destruction de données.

| Service | Propriétaire des tables | Mécanisme | Source de vérité |
|---|---|---|---|
| Véhicule | `vehicules` | SQL versionné | `db/migrations/001_create_vehicules.sql` |
| Chauffeur | `chauffeurs` | SQL versionné | `module-chauffeur/db/migrations/001_create_chauffeurs.sql` |
| Carburant | `carburants` | Alembic | `module-carburant/alembic/versions/0001_create_carburants.py` |
| Entretien | `entretiens`, `entretien_historique`, `alertes`, `declarations_panne`, `integrations_outbox`, `integrations_idempotence`, `gestionnaires`, `migrations` | Laravel Migrations | `module-entretien/database/migrations/` |

## Règles appliquées

- `gestion_flotte` est la seule base utilisée par les quatre services. Aucune base
  `vehicule_db`, `flotte_db` ou `entretien_db` n'est créée.
- Les scripts `db/init/*.sql` ne servent qu'au **premier démarrage** d'un volume MySQL
  vide. Ils `SOURCE` les migrations versionnées : le DDL n'est donc pas dupliqué.
- Toutes les migrations sont idempotentes (`CREATE TABLE IF NOT EXISTS`) : les réappliquer
  ne modifie aucune ligne existante.
- Aucun `DROP TABLE`, `DROP DATABASE`, `migrate:fresh` ni suppression de volume n'est utilisé.
- Les relations entre services restent des appels REST ; le partage du schéma MySQL ne
  court-circuite pas les règles métier de chaque service.

Note : `affectations` et `echeances` sont des concepts métier calculés. Ils sont stockés
respectivement dans `chauffeurs.vehicule_id` et dans les colonnes d'échéance de
`entretiens` + la table `alertes`. Aucune table vide supplémentaire n'est créée, afin de
ne pas introduire une seconde source de vérité.

## Application

- Véhicule : la migration est sourcée par `db/init/01-vehicule.sql` lors de la création du
  volume, et peut être réappliquée manuellement sur une installation existante :

  ```bash
  docker compose exec -T db-commune mysql -uroot -p"$DB_ROOT_PASSWORD" gestion_flotte \
    < db/migrations/001_create_vehicules.sql
  ```

- Chauffeur : `module-chauffeur/migrations.py` applique les fichiers `*.sql` de
  `module-chauffeur/db/migrations/` au démarrage du service (avec repli `create_all()`
  uniquement pour les tests SQLite).
- Carburant : `alembic upgrade head` est lancé au démarrage du service dans
  `module-carburant/main.py`.
- Entretien : `php artisan migrate --force` est lancé au démarrage des conteneurs
  `entretien-app` et `entretien-scheduler`.
