# gestion-flotte-vehicules-ws2026

## Architecture — base de données partagée (choix assumé)

Les quatre microservices **Véhicule**, **Chauffeur**, **Carburant** et **Entretien** partagent
volontairement une seule base MySQL 8 `gestion_flotte` (service `db-commune` dans
`docker-compose.yml`).

- Ce choix est **délibéré et validé** : il ne faut pas créer de base dédiée `vehicule_db`,
  `flotte_db` ou `entretien_db`, ni réintroduire un script `db/init/02-entretien.sql`.
- Il diverge sciemment de la recommandation « une base par service » du cahier des charges ;
  l'écart est assumé et documenté, en particulier dans `module-entretien/README.md`.
- Les services restent découplés côté applicatif : appels HTTP avec `SERVICE_API_KEY`, JWT
  partagé, et saga/outbox pour la synchronisation des statuts de véhicules. Le partage du
  schéma MySQL ne court-circuite jamais une règle métier d'un autre service.
- Configuration de référence : `.env.example` (`DB_NAME` / `ENTRETIEN_DB_NAME`) et
  `module-entretien/.env.example` (`DB_DATABASE`). Les modules Python construisent leur URL
  depuis les mêmes variables `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`.
- Migrations : voir `db/migrations/README.md`. Chaque table n'a qu'une source de vérité :
  SQL versionné pour Véhicule/Chauffeur, Alembic pour Carburant, Laravel Migrations pour
  Entretien.