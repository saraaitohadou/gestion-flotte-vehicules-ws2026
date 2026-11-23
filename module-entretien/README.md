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

La **source de vérité unique** est `config/vehicule.php` (variables `VEHICULE_SERVICE_URL`,
`VEHICULE_API_PREFIX`, `VEHICULE_SERVICE_TIMEOUT`, `SERVICE_API_KEY`). La clé obsolète
`entretien.vehicule_service` a été supprimée. Le service Chauffeur a sa propre config
`config/chauffeur.php`.

En mode stack racine (`docker compose -f docker-compose.yml up -d`), `VEHICULE_SERVICE_URL`
vaut `http://vehicule-service:80` et `VEHICULE_API_PREFIX=/api`. La surcharge
`docker-compose.real.yml` aligne les deux conteneurs Entretien sur ces mêmes valeurs.

### Base de données partagée — choix architectural assumé

Le service Entretien utilise **la base commune `gestion_flotte`**, la même que les modules
Véhicule et Chauffeur (`ENTRETIEN_DB_NAME=gestion_flotte` dans la stack racine). Ce n'est ni un
fallback provisoire ni un oubli : c'est un **choix architectural validé** pour les trois microservices.

- **Aucune base `entretien_db` ne doit être créée.** Le script `db/init/02-entretien.sql` a été
  supprimé et aucune configuration du dépôt ne référence cette base.
- Les tables `entretiens`, `alertes`, `declarations_panne`, `integrations_outbox` coexistent avec
  `vehicules`, `chauffeurs`, etc. dans le même schéma et le même volume MySQL.
- `php artisan migrate` au démarrage est idempotent : il ne recrée ni ne remplace les tables
  existantes.
- Ce choix **diverge volontairement** de la p. 8 du cahier des charges (« une base par service »).
  L'écart est assumé et documenté ici comme tel, et non traité comme un défaut à corriger.
- L'autonomie des services reste assurée au niveau applicatif : appels HTTP avec `SERVICE_API_KEY`,
  JWT partagé, et saga/outbox pour la cohérence des statuts Véhicule.
- Chaque module conserve ses propres tables et migrations : la base partagée ne doit pas servir
  à court-circuiter les appels HTTP entre services.

Mocks : vehicule 1/2/5 = DISPONIBLE, 3 = HORS_SERVICE, 4 = ARCHIVE ; chauffeur 1 = EN_MISSION,
2 = DISPONIBLE, 3 = EN_CONGE, 4 = INACTIF.

## Écarts assumés par rapport aux diagrammes

Trois écarts assumés sont volontairement conservés : ils sont documentés ici plutôt que modifiés dans le code.

- **Diagramme de classes — type d'alerte `AVANT_ECHEANCE`** : le code utilise la constante `Alerte::PROCHE`
  (valeur `PROCHE`, voir `app/Models/Alerte.php` et `app/Services/EcheanceService.php`). `PROCHE` correspond à
  `AVANT_ECHEANCE` dans le diagramme : même sens (échéance à venir dans les 15 jours ou 500 km), simple
  différence de dénomination. Aucun renommage n'est effectué : ni la constante, ni la valeur stockée,
  ni la migration, ni les alertes existantes.
- **Cas d'utilisation — acteur « Mécanicien »** : il n'existe pas de rôle distinct. Le « Mécanicien » du
  diagramme correspond au rôle `GESTIONNAIRE` du système : il planifie, démarre, termine et annule les
  entretiens. Les seuls rôles sont `ADMINISTRATEUR`, `GESTIONNAIRE` et `CHAUFFEUR`
  (`app/Support/AuthUser.php`), conformément au §4.2 du cahier des charges. Aucun rôle `MECANICIEN`
  n'est créé.
- **Cas d'utilisation / activité — changement de statut du véhicule à la planification** : le diagramme place
  le passage du véhicule en `EN_MAINTENANCE` dès la planification. Le code vérifie bien la disponibilité à la
  création (`GET` Véhicule dans `EntretienController::store()` via `VehiculeClient::verifierUtilisable()`),
  mais ne modifie pas le statut du véhicule à ce moment-là : `EN_MAINTENANCE` n'est atteint qu'au **démarrage**
  (`demarrer()`), et le retour à `DISPONIBLE` a lieu à la clôture ou à l'annulation d'un entretien `EN_COURS`.
  Cet écart est imposé par les règles §4.2 (A5) et §4.4 (B1), prioritaires sur le diagramme (voir D3), et
  confirmé par `test_planifier_un_entretien_ne_change_pas_le_statut_du_vehicule`.

## Regles metier

- Cycle de vie : PLANIFIE -> EN_COURS -> TERMINE ; PLANIFIE ou EN_COURS -> ANNULE. Aucun retour en arriere.
- Creer : un vehicule **EN_MISSION** est refuse (`VEHICULE_EN_MISSION`) ; les declarations de panne, elles, l'acceptent.
  Planifier sur un vehicule **EN_MAINTENANCE** reste autorise.
- Demarrer (regle §4.4) : le vehicule doit etre **DISPONIBLE** au depart, sinon refus 409
  `VEHICULE_NON_DISPONIBLE` (statut courant dans `error.details.statut_vehicule`), avant toute ecriture
  locale et avant toute ligne d'outbox. C'est le seul moyen d'atteindre **EN_MAINTENANCE**.
  Un seul entretien EN_COURS par vehicule, garanti en base
  par un index unique sur une colonne generatee (`entretiens_en_cours_par_vehicule_unique`, migration 000003).
- Terminer : le vehicule repasse DISPONIBLE, le kilometrage est mis a jour.
- Echeance (preventif) : calculee depuis `config/entretien.php` (ex. VIDANGE = 6 mois / 10 000 km).
  Un changement de `categorie` recalcule immediatement l'echeance et clot les alertes de l'ancienne.
- Alertes : le CRON ne **reouvre jamais** une alerte deja resolue (`resolue_at` n'est jamais remis a null).
  Un entretien ne peut pas avoir `PROCHE` et `EN_RETARD` ouverts en meme temps : `EN_RETARD` l'emporte,
  et les alertes devenues sans objet sont resolues automatiquement (date ou categorie modifiee).
- Kilometrage saisi >= kilometrage du vehicule, **a la creation et au PUT** (`KILOMETRAGE_INVALIDE`).
  Le PUT n'interroge le service Vehicule que si le client modifie effectivement `kilometrage`.
- Conversion panne -> entretien : le kilometrage declare est obligatoire et verifie contre celui du
  vehicule (`KILOMETRAGE_DECLARATION_MANQUANT`, `KILOMETRAGE_VEHICULE_INDISPONIBLE`, `KILOMETRAGE_INVALIDE`).
  Verrou de ligne + update conditionnel + index unique : une declaration ne produit qu'un seul entretien.
- Types : la liste des types valides est definie par les cles de `config/entretien.types`.
  Ajouter un type (ex. `MAINTENANCE`) se fait uniquement dans la configuration, avec ses categories
  et periodicites : aucune enum PHP a modifier. Le calcul d'echeance utilise le type de l'entretien.
- Format d'erreur : `{"error": {"code", "message", "details"}}`. Succes : `{"data": ...}`.

## Synchronisation Véhicule (saga avec compensation)

Les transitions qui touchent le véhicule (`demarrer`, `terminer`, `annuler`) suivent une saga :

1. **Pré-vol** : le service Véhicule est interrogé. S'il est injoignable, aucun écriture locale n'est faite (`VEHICULE_SERVICE_INDISPONIBLE`).
2. **Transaction locale** : l'entretien, son historique **et une ligne dans `integrations_outbox`** sont écrits ensemble. Aucun PATCH n'est envoyé avant le commit.
3. **PATCH Véhicule** : envoyé après le commit. Chaque opération porte sa **propre clé d'idempotence**
   (statut et kilometrage n'ont jamais la même clé) et sa trace d'application dans `operations_appliquees`.
4. **Rejou idempotent** : avant toute nouvelle tentative, l'état réel du véhicule est relu. Une opération
   déjà visible (ou déjà tracée, y compris un 409 « même état » renvoyé par Véhicule) n'est jamais
   renvoyée ; le rejou continue avec les opérations restantes.
5. **Limite de tentatives** : `SYNCHRONISATION_TENTATIVES_MAX` (défaut 3). La dernière tentative en échec
   fait passer la ligne en `ECHEC_DEFINITIF` (dead-letter) au lieu d'une boucle infinie. Le CRON affiche
   séparément ce compte, et le contrôleur renvoie 503 `SYNCHRONISATION_TENTATIVES_EPUISEES`.
6. **Compensation** : si le PATCH échoue, l'état réel du véhicule est relu et seules les opérations
   réellement appliquées sont restaurées.
   - Mutation appliquée → le véhicule est restauré **et** la modification locale est annulée (trace dans l'historique, code `SYNCHRONISATION_VEHICULE_ECHOUEE`).
   - L'état local a évolué entre-temps → **compare-and-swap sous verrou** sur `entretien_apres` : la
     compensation est refusée (`COMPENSATION_IGNOREE`, 409) plutôt que d'écraser une transition récente.
   - État illisible → on ne devine pas : l'outbox reste en attente et le CRON rejoue la mutation (`SYNCHRONISATION_VEHICULE_EN_ATTENTE`).

La clôture met **toujours** à jour le compteur du véhicule, même si le client n'envoie pas `kilometrage`
(valeur de l'entretien, ou valeur courante du véhicule si elle est supérieure).

Rejou manuel : `php artisan entretiens:synchroniser-vehicules --limit=50` (automatique chaque minute via le
scheduler). La sortie distingue les lignes appliquées, encore en échec et en dead-letter.

## Declaration de panne

- `POST /api/declarations-panne` : un chauffeur declare une panne (categorie PANNE ou REPARATION).
  Vehicule HORS_SERVICE/ARCHIVE refuse ; EN_MISSION accepte. Chauffeur EN_CONGE/INACTIF refuse.
  Si le service Chauffeur est injoignable : mode degrade (`chauffeur_verifie = false`).
- `GET /api/declarations-panne`, `GET /api/declarations-panne/{id}`
- `PATCH /api/declarations-panne/{id}/prendre-en-charge` : cree un entretien CORRECTIF PLANIFIE (`declaration_panne_id`
  renseigné), statut TRAITEE. Le km declare doit être present et >= celui du véhicule.
  Un rejou de la même requête renvoie la ressource existante : aucun deuxième entretien n'est créé.
- `PATCH /api/declarations-panne/{id}/rejeter` : statut REJETEE, sous verrou et update conditionnel.
- Une declaration deja traitee renvoie 422 `DECLARATION_DEJA_TRAITEE` (sauf rejou idempotent de prise en charge,
  qui renvoie 200 avec l'entretien existant). Une modification concurrente renvoie 409 `DECLARATION_CONFLIT`.
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

Attendu : **119 tests, 347 assertions** (suite exécutée via l'image Docker reconstruite, SQLite en mémoire). Les tests
simulent les services externes (`Http::fake`) et couvrent notamment :
- la saga/outbox : rejou idempotent par opération, 409 « même état », limite de tentatives/dead-letter,
  compensation avec compare-and-swap (`SynchronisationVehiculeTest`) ;
- la conversion panne → entretien : km absent/invalide, rejou idempotent, concurrence avec modèle périmé,
  index unique (`DeclarationPanneTraitementTest`) ;
- la cohérence des alertes : non-réouverture, exclusivité `PROCHE`/`EN_RETARD`, changement de catégorie
  (`AlertesNonReouvertesTest`) ;
- la documentation : tout code d'erreur émis par `app/` est documenté dans `openapi.yaml`
  (`OpenApiErreursDocumenteesTest`) ; création sur véhicule inexistant → 404 sans INSERT et planification
  sans changement de statut Véhicule (`EntretienApiTest`) ;
- le contrôle d'accès (`SecuriteAccesTest`) et la contrainte d'unicité `EN_COURS`.

Le harnais (`tests/TestCase.php`) **n'injecte plus aucun token** : chaque test passe explicitement
ses en-têtes JWT via `tokenHeaders()`. Aucun test ne repose sur un privilège implicite.

## Authentification et roles

Authentification par JWT (HS256). Le service **verifie** le token ; il ne l'emet pas
(le token est emis par le service Chauffeur, `POST /api/auth/login`, meme `JWT_SECRET`).
Header : `Authorization: Bearer <token>`.
Claims : `sub` (id), `role`, `vehicule_id` (optionnel), `id_chauffeur` (pour un chauffeur), `exp`.

**Contrôle d'accès (fail-closed)** : une requête sans JWT est rejetée en 401 `NON_AUTHENTIFIE` ;
`AuthUser` ne devient plus jamais ADMINISTRATEUR par défaut.

**Déclarations de panne** :
- un CHAUFFEUR ne voit que ses propres déclarations (filtre serveur sur `chauffeur_id`, même si un
  `?vehicule_id=` est fourni) ; les ADMIN/GESTIONNAIRE voient tout ;
- `chauffeur_id` est **ignoré dans le corps** pour un CHAUFFEUR : il est dérivé du JWT
  (`id_chauffeur`, sinon `sub` numérique). Un token CHAUFFEUR sans identité est refusé en
  403 `IDENTITE_CHAUFFEUR_MANQUANTE` ;
- si le JWT porte `vehicule_id`, le chauffeur ne peut consulter/déclarer que ce véhicule
  (403 `ACCES_REFUSE`) ;
- un ADMINISTRATEUR peut toujours déclarer pour un chauffeur désigné dans le corps.
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