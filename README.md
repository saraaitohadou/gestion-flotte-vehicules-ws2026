# gestion-flotte-vehicules-ws2026

## Architecture — base de données partagée (choix assumé)

Les trois microservices **Véhicule**, **Chauffeur** et **Entretien** partagent volontairement
une seule base MySQL 8 `gestion_flotte` (service `db-commune` dans `docker-compose.yml`).

- Ce choix est **délibéré et validé** : il ne faut pas créer de base dédiée `entretien_db`,
  ni réintroduire un script `db/init/02-entretien.sql`.
- Il diverge sciemment de la recommandation « une base par service » du cahier des charges ;
  l'écart est assumé et documenté, en particulier dans `module-entretien/README.md`.
- Les services restent découplés côté applicatif : appels HTTP avec `SERVICE_API_KEY`, JWT
  partagé, et saga/outbox pour la synchronisation des statuts de véhicules.
- Configuration de référence : `.env.example` (`DB_NAME` / `ENTRETIEN_DB_NAME`) et
  `module-entretien/.env.example` (`DB_DATABASE`).