# Audit REST inter-services — Véhicule / Chauffeur / Entretien

Date : 2026-11-23. Périmètre : `module-vehicule`, `module-chauffeur`, `module-entretien`, `docker-compose.yml`.
Aucune donnée ni volume supprimé, aucun `migrate:fresh/rollback`, `db:wipe`, `down -v`, aucun commit/push, `module-carburant` intact.
Base unique vérifiée : `gestion_flotte` (aucune `entretien_db`).

---

## 1. Schéma réel des communications REST

```
Frontend Entretien ──JWT──> entretien-app:8004  (/api/...)
Frontend Chauffeur ──JWT──> chauffeur-service:8000 (/api/auth, /api/chauffeurs, /espace-chauffeur)

entretien-app ──X-API-Key (SERVICE_API_KEY)──> vehicule-service:80
   GET  /api/vehicules                 (liste)
   GET  /api/vehicules/{id}            (statut, kilométrage)
   PATCH /api/vehicules/{id}/statut    (EN_MAINTENANCE / DISPONIBLE, Idempotency-Key)
   PATCH /api/vehicules/{id}/kilometrage

entretien-app ──X-API-Key──> chauffeur-service:8000
   GET /api/chauffeurs/{id}/eligibilite
   GET /api/chauffeurs

chauffeur-service ──X-Service-Key + X-API-Key + Bearer JWT de service──> entretien-app:8004  (APRÈS CORRECTION)
   GET  /api/vehicules/{id}/entretiens
   GET  /api/entretiens?vehicule_id={id}
   POST /api/entretiens

chauffeur-service ──X-Service-Key / X-API-Key (+ Bearer utilisateur)──> vehicule-service:80
   GET  /api/vehicules/{id}/disponibilite
   PATCH /api/vehicules/{id}/statut
   GET  /api/vehicules, GET /api/vehicules/{id}

chauffeur-service ──X-Service-Key──> carburant-service:8000
   POST /carburants        (module non modifié)
```

### Authentification observée

| Cible | Accepté | Refusé |
|---|---|---|
| Entretien `/api/...` | `Authorization: Bearer <JWT HS256>` (roles `ADMINISTRATEUR`, `GESTIONNAIRE`, `CHAUFFEUR`) | clé de service seule → **401** (mesuré) |
| Véhicule `/api/...` | `X-API-Key: SERVICE_API_KEY` **ou** `Bearer` JWT role `ADMINISTRATEUR`/`ADMIN` | aucune auth → **401** (mesuré) |
| Chauffeur `/api/chauffeurs/...` | `X-API-Key` ou `X-Service-Key` ou `Bearer` admin | aucune auth → **401** (mesuré) |

Les appels sortants d’Entretien n’envoient **pas** de JWT (uniquement `X-API-Key`, `Accept: application/json`, `Idempotency-Key`). Timeouts : 3 s (Entretien), 4 s (Chauffeur). Aucun retry HTTP : la saga/outbox `integrations_outbox` gère la compensation applicative.

### Gestion des erreurs

- Entretien → Véhicule : 404 → `404 VEHICULE_INTROUVABLE` ; 4xx sur PATCH → `409 VEHICULE_MISE_A_JOUR_REFUSEE` ; 5xx/exception → `503 VEHICULE_SERVICE_INDISPONIBLE`.
- Entretien → Chauffeur : 404 → `404 CHAUFFEUR_INTROUVABLE` ; non-2xx/exception → `503 CHAUFFEUR_SERVICE_INDISPONIBLE`. `selectionnable=false` → `422 CHAUFFEUR_NON_SELECTIONNABLE`.
- Chauffeur → Véhicule : 404, 409, 422, 503 mappés (`VEHICULE_INTROUVABLE`, `VEHICULE_INDISPONIBLE`, `VEHICULE_STATUT_REFUSE`, `VEHICULE_SERVICE_INDISPONIBLE`).
- Chauffeur → Entretien : erreurs remontées en `ERREUR_ENTRETIEN` avec le code HTTP d’origine.

---

## 2. Endpoints testés (preuves HTTP réelles)

| Service | Méthode / chemin | Auth | Code observé |
|---|---|---|---|
| Entretien | `GET /api/health` | public | 200 |
| Entretien | `GET /api/entretiens` | JWT | 200 |
| Entretien | `GET /api/entretiens` | sans token / token invalide | 401 / 401 |
| Entretien | `POST /api/entretiens` | JWT GESTIONNAIRE | 201 |
| Entretien | `GET /api/entretiens/{id}` | JWT | 200 |
| Entretien | `PUT /api/entretiens/{id}` | JWT | 200 |
| Entretien | `PATCH /{id}/demarrer`, `/terminer`, `/annuler` | JWT | 200 / 200 / 200 |
| Entretien | `DELETE /api/entretiens/{id}` | JWT ADMINISTRATEUR | 200 |
| Entretien | `GET /api/entretiens?archives=seulement` | JWT | 200 |
| Entretien | `GET /api/vehicules/{id}/entretiens` | JWT | 200 |
| Entretien | `GET /api/vehicules/{id}` (**nouveau proxy**) | JWT ADMIN/GESTIONNAIRE | 200 (200/404/503/403 mesurés en tests) |
| Entretien | `GET /api/alertes` | JWT | 200 |
| Véhicule | `GET /api/vehicules`, `GET /api/vehicules/{id}` | X-API-Key | 200 / 200 |
| Véhicule | `GET /api/vehicules/999999` | X-API-Key | 404 |
| Véhicule | `GET /api/vehicules/{id}` sans auth | — | 401 |
| Chauffeur | `GET /api/chauffeurs/{id}/eligibilite` | X-API-Key | 200 |
| Chauffeur | `GET /api/chauffeurs/999999/eligibilite` | X-API-Key | 404 |
| Chauffeur | `GET /api/chauffeurs/{id}/eligibilite` sans auth | — | 401 |
| Chauffeur → Entretien | `GET /espace-chauffeur/me/entretiens` | JWT CHAUFFEUR | 200 (après correction) |

Fichiers de preuves : `postman/evidence/probe-entretien.json` (20 sondes), `scenarios.json` (15), `alerts.json` (22), `post-fix-chauffeur-entretien.json`, `db-consistency.json`, `final-lifecycle.json` (11 appels du cycle final).

---

## 3. Résultats des scénarios obligatoires

| # | Scénario | Résultat | Code |
|---|---|---|---|
| S01 | Créer un entretien (véhicule 1 existant) | id 15, `PLANIFIE`, appel Véhicule réussi | **201** |
| S02 | Créer un entretien (véhicule 999999) | `VEHICULE_INTROUVABLE`, aucune ligne créée | **404** |
| S03 | Consulter les entretiens | liste paginée | **200** |
| S04 | Planifier un entretien | id 16 `PLANIFIE` (le véhicule reste DISPONIBLE) | **201 / 200** |
| S05 | Démarrer + statut véhicule | entretien 15 `EN_COURS`, véhicule 1 `EN_MAINTENANCE` | **200** |
| S06 | Terminer + mises à jour | `TERMINE`, véhicule 1 `DISPONIBLE`, km 45 400, échéance 2027-05-23 / 55 400 km | **200** |
| S07 | Annuler un entretien | entretien 16 `ANNULE` | **200** |
| S08 | Archiver un entretien | `archive_at` renseigné (archivage logique) | **200** |
| S09 | Afficher les archives | entretien 16 présent | **200** |
| S10 | Générer/consulter les alertes | 1 `PROCHE` (entretien 8) + 1 `EN_RETARD` (entretien 17) | **200** |
| S10f/g | Relancer la génération | toujours 2 alertes, aucun doublon | **200** |
| S10h/i | Annuler l’entretien 17 | son alerte fermée, celle de l’entretien 8 reste ouverte | **200** |
| S10j/k,l,m,n | Alerte résolue manuellement puis CRON relancé | non réouverte | **200** |
| S11 | Scheduler | `schedule:list` : `0 2 * * * entretiens:verifier-echeances` + `* * * * * entretiens:synchroniser-vehicules` ; `schedule:run` exécute la tâche minute | **200** |
| S12 | Token absent / invalide | `NON_AUTHENTIFIE` / `TOKEN_INVALIDE` | **401** |
| S13 | Service Véhicule indisponible (URL `127.0.0.1:9`, sans arrêter le conteneur) | `503 VEHICULE_SERVICE_INDISPONIBLE`, aucune écriture locale | **503** |
| S14 | Cohérence | `integrations_outbox` : 8 lignes `APPLIQUE` ; 3 TERMINE / 1 PLANIFIE / 8 ANNULE ; véhicule 1 cohérent | **OK** |
| S15 | Cycle HTTP final (véhicule 2 / Clio) | `POST` 201 `PLANIFIE` → `PATCH /demarrer` 200 → véhicule `EN_MAINTENANCE` (poll 1, 0,04 s) → `PATCH /terminer` 200 → véhicule `DISPONIBLE` 62 500 km (poll 1, 0,03 s) → `DELETE` 200 archivage ; entretien **#19** | **201 / 200 / 200 / 200 / 200** |

Comptage initial : `vehicules=3, chauffeurs=4, entretiens=9, alertes=0`. Après tests : entretiens 12, alertes 2 (toutes résolues à la fin des tests de non-réouverture).

---

## 4. Problèmes détectés et corrections

### Corrigé — Chauffeur → Entretien : chemin et authentification (P1)

- **Avant** (`module-chauffeur/clients.py`) : `GET /vehicules/{id}/entretiens`, `GET /entretiens?...`, `POST /entretiens` avec `X-Service-Key`/`X-API-Key` seulement.
- **Mesuré avant correction** (depuis `chauffeur-service`) :
  - `/vehicules/1/entretiens` → **404** (route inexistante hors `/api`) ;
  - `/api/vehicules/1/entretiens` avec clé seule → **401** (`NON_AUTHENTIFIE`) ;
  - la même URL avec `Bearer <JWT>` → **200**.
  Conséquence : `GET /espace-chauffeur/me/entretiens` renvoyait `[]` et le signalement échouait silencieusement.
- **Correction appliquée** (1 fichier, 22 ajouts / 8 suppressions) : préfixe `/api` sur les trois appels et `_entretien_headers()` qui émet un JWT de service court via `auth.issue_token(sub="service-chauffeur", role=ADMINISTRATEUR, ttl=300)` en utilisant le `JWT_SECRET` déjà partagé. Aucune règle métier, table ou erreur modifiée.
- **Après** : `GET /espace-chauffeur/me/entretiens` avec un vrai JWT CHAUFFEUR → **200** avec les entretiens réels du véhicule 1 (preuve `post-fix-chauffeur-entretien.json`).
- `docker compose build chauffeur-service` puis `up -d --no-deps chauffeur-service` : exit 0, conteneur `Up`.

### Corrigé — Frontend Entretien : liste vide et aucun Fetch/XHR (P1)

- **Cause racine mesurée** : aucun serveur n’écoutait sur `127.0.0.1:5176` / `localhost:5176`. L’onglet existant `http://localhost:5176/pannes/nouvelle` renvoyait **502 Bad Gateway** (`netstat` : aucun PID en écoute sur 5173–5177). React n’était donc jamais monté et **aucune requête Fetch/XHR n’était émise** : la liste de véhicules restait vide pour cette raison, pas à cause de `api.js` ni du backend.
- **Action immédiate** : `npm run dev -- --host 127.0.0.1 --port 5176 --strictPort` dans `frontend-entretien/`. `GET /api/vehicules` répond alors **200** avec 3 véhicules et le `<select>` se remplit (preuves §9).
- **Défauts réels restants corrigés** (erreurs auparavant muettes) :
  - `frontend-entretien/src/api.js` : `messageErreur()` explicite, codes `SERVICE_INJOIGNABLE`, `SESSION_EXPIREE`, `REPONSE_INVALIDE`, garde `window`, helper `vehicule(id)`.
  - `frontend-entretien/src/auth.js` : gardes `window`/`localStorage` (import possible sous Node).
  - `frontend-entretien/src/pages/EntretienForm.jsx` : états de chargement/d’erreur des véhicules, bouton **Reessayer**, soumission désactivée tant que la liste n’est pas chargée, 503/transport affichés.
  - `frontend-entretien/src/pages/DeclarerPanne.jsx` : mêmes garanties pour véhicules **et** chauffeurs ; aucun `chauffeur_id` n’est envoyé avec un JWT CHAUFFEUR.
  - `frontend-entretien/src/pages/EntretienDetail.jsx` : consomme le nouveau proxy et affiche `Statut vehicule`, valeur faisant foi après Démarrer/Terminer.
  - `frontend-entretien/package.json` + `tests/api.test.mjs` : script `npm test` et 5 tests.
- **Backend d’accompagnement** : `module-entretien/routes/api.php` (+1 route), `module-entretien/app/Http/Controllers/VehiculeController.php` (`show()`), `module-entretien/tests/Feature/VehiculeDetailProxyTest.php` (4 tests).
- **Rebuild** : `docker compose up -d --build entretien-app entretien-scheduler` (la 1re tentative a échoué sur un 504 GitHub transitoire pendant `composer install` ; la 2e a réussi).
- Aucune modification supplémentaire de `module-vehicule`, `module-chauffeur` (hors correctif antérieur déjà livré) ni de `module-carburant`.

### Soupçons infirmés par les tests réels (aucune modification)

- `module-vehicule/Dockerfile` sans routeur : `/vehicules/1` **et** `/api/vehicules/1` renvoient 200 (le serveur PHP intégré sert bien `public/index.php`). Pas de correctif.
- « GET /api/chauffeurs non authentifié autorisé » : mesuré **401**. Le contournement historique a déjà été supprimé dans `auth.py`.
- `ENTRETIEN_SERVICE_URL` absent du compose : la valeur par défaut `http://entretien-app:8004` est correcte et fonctionne. La variable `.env` côté hôte (`IP_PC_ENTRETIEN`) ne doit **pas** être injectée telle quelle dans le conteneur. Pas de correctif.

### Limites d’environnement (pas des défauts de code)

- `POST /api/gestionnaires/login` avec `ADMIN_USERNAME/ADMIN_PASSWORD` du `.env` racine → **401** : ces identifiants appartiennent au service Chauffeur ; Entretien s’authentifie contre la table `gestionnaires` qui contient uniquement `fati` (mot de passe inconnu de l’audit). Les scénarios ont donc utilisé des JWT signés avec `JWT_SECRET` via `php artisan tinker` (voir §8). Pour Postman, renseignez `token` avec un JWT valide ou un compte gestionnaire existant.
- `schedule:run` lancé à 16:08 n’exécute que la tâche `* * * * *` ; la tâche d’alertes est dailyAt 02:00. Son enregistrement a été vérifié par `schedule:list`, et la commande qu’elle appelle a été exécutée directement avec succès.
- La table d’outbox s’appelle `integrations_outbox` (et non `integration_outbox`).

---

## 5. Tests automatisés — distinction mocks / réel

| Nature | Commande | Résultat |
|---|---|---|
| **Réel inter-conteneurs** | sondes curl/httpx depuis `entretien-app` et `chauffeur-service` vers les 3 services | 20 + 15 + 22 + 1 requêtes, codes ci-dessus |
| **Réel inter-conteneurs** | `clients.lister_entretiens_vehicule(1)` depuis le conteneur Chauffeur | liste JSON réponse d’Entretien |
| **Réel HTTP (hôte → conteneurs)** | `curl.exe` : script `lifecycle.ps1`, véhicule 2 | 11 appels, **tous 200/201** ; cycle complet en < 1 s, preuve `postman/evidence/final-lifecycle.json` |
| **Réel navigateur** | Playwright/Chromium sur `127.0.0.1:5176` | GET/POST/PATCH 200/201/200 + `Statut vehicule DISPONIBLE (62 500 km)` (§9) |
| Mocké (unitaire) | `docker compose exec -T entretien-app php artisan test` | **136 passed (392 assertions)**, 6,43 s (132 initiaux + 4 nouveaux) |
| Mocké (unitaire) | `npm test` dans `frontend-entretien/` | **5 pass / 0 fail** (`node --test tests/*.test.mjs`) |
| Mocké (unitaire) | tests Chauffeur (`test_auth_integration.py`, `test_unitaires_chauffeur.py`) | non exécutés : `pytest` absent du conteneur et de l’hôte (ils utilisent `monkeypatch` sur `httpx`, donc ne prouvent pas la communication réelle) |
| Build | `npm run build` | OK (`dist/assets/index-E_g0sKLN.js`, 212,18 kB) |
| Syntaxe | `python -m py_compile clients.py auth.py`, `import clients, auth` | OK |
| Export | validation JSON des deux fichiers Postman + `final-lifecycle.json` | OK |

**Important :** les 136 tests Laravel utilisent `Http::fake()`, les 5 tests frontend remplacent `globalThis.fetch` et les tests Python monkeypatch `httpx` ; ils valident la logique et le mapping d’erreurs, **pas** la connectivité. Celle-ci est prouvée uniquement par les sondes HTTP réelles et le cycle `curl.exe`/navigateur ci-dessus.

---

## 6. Collection Postman exportable

`postman/REST_Audit_Flotte.postman_collection.json` — **58 requêtes**, 7 dossiers :
`1. Authentification`, `2. Service Véhicule`, `3. Service Chauffeur`, `4. Service Entretien`, `5. Alertes`, `6. Communication inter-services`, `7. Tests des erreurs HTTP`.
Chaque requête contient méthode, URL complète, headers, auth, body JSON exact, résultat et code attendu en description. Les scripts de test des 3 logins renseignent automatiquement les variables de token. La requête **`Statut reel du vehicule (proxy Entretien)`** (`GET {{base_url_entretien}}/api/vehicules/{{vehicule_id}}`) a été ajoutée dans le dossier 4.

## 7. Environnement Postman exportable

`postman/REST_Audit_Flotte.postman_environment.json` — variables :
`base_url_vehicule` (`http://127.0.0.1:8001`), `base_url_chauffeur` (`http://127.0.0.1:8002`), `base_url_entretien` (`http://127.0.0.1:8004`), `token`, `vehicule_id` (1), `chauffeur_id` (1), `entretien_id` (19, entretien du cycle final archivé), plus `service_api_key`, `admin_username`, `admin_password`, `chauffeur_cin`, `chauffeur_permis`, `chauffeur_admin_token`, `chauffeur_token`, `token_service_chauffeur`.

## 8. Reproduction exacte

```powershell
# 0) État initial (lecture seule)
docker compose ps
docker compose exec -T db-commune sh -c "mysql -uroot -p`$MYSQL_ROOT_PASSWORD -N -e 'SHOW DATABASES;' 2>/dev/null | grep gestion_flotte"

# 1) JWT de test (Entretien signe avec JWT_SECRET partagé)
docker compose exec -T entretien-app php artisan tinker --execute "echo App\Support\Jwt::encode(['sub' => '1', 'name' => 'audit', 'role' => 'GESTIONNAIRE', 'exp' => time()+7200], config('auth_jwt.secret'));"
# Mettre ce token dans la variable Postman "token".
# Pour archiver, générer le même token avec role ADMINISTRATEUR.

# 2) Sondes inter-services (exemples)
docker compose exec -T entretien-app curl -i -H "X-API-Key: <SERVICE_API_KEY>" http://vehicule-service/api/vehicules/1
docker compose exec -T entretien-app curl -i -H "X-API-Key: <SERVICE_API_KEY>" http://chauffeur-service:8000/api/chauffeurs/1/eligibilite
docker compose exec -T entretien-app curl -i -H "Authorization: Bearer <token>" http://entretien-app:8004/api/entretiens

# 3) Scénario complet via la collection Postman
# Importer les deux JSON, sélectionner l'environnement, puis exécuter les dossiers dans l'ordre.
# Les corps JSON et codes attendus sont dans chaque requête.

# 4) Alertes / Scheduler
docker compose exec -T entretien-app php artisan entretiens:verifier-echeances
docker compose exec -T entretien-app php artisan schedule:list
docker compose exec -T entretien-app php artisan schedule:run
docker compose exec -T entretien-app curl -i -H "Authorization: Bearer <token>" "http://entretien-app:8004/api/alertes?per_page=100"

# 5) Service indisponible (sans arrêter le service)
docker compose exec -T -e VEHICULE_SERVICE_URL=http://127.0.0.1:9 entretien-app php artisan tinker --execute "try { app(App\Services\VehiculeClient::class)->find(1); } catch (App\Exceptions\BusinessException `$e) { echo `$e->status.'|'.`$e->errorCode; }"
# Attendu : 503|VEHICULE_SERVICE_INDISPONIBLE

# 6) Tests automatisés (mocks)
docker compose exec -T entretien-app php artisan test
cd frontend-entretien
& "C:\Program Files\nodejs\npm.cmd" test
& "C:\Program Files\nodejs\npm.cmd" run build
cd ..

# 7) Frontend SUR LE PORT 5176 (étape indispensable : sans listener, 502 et zéro Fetch/XHR)
cd frontend-entretien
& "C:\Program Files\nodejs\npm.cmd" run dev -- --host 127.0.0.1 --port 5176 --strictPort

# 8) Cycle HTTP réel complet (curl.exe, véhicule 2 / Clio) : 11 appels, tous 200/201
powershell -NoProfile -ExecutionPolicy Bypass -File postman\lifecycle-final.ps1
# Écrit postman/evidence/final-lifecycle.json et archive l'entretien créé.
# Le token ADMINISTRATEUR est généré automatiquement à chaque exécution (ligne 4 du script).
# Important sous PowerShell : -d '{...}' retire les guillemets ; le script écrit donc le corps
# dans un fichier ASCII sans BOM et utilise -d @fichier.

# 9) UI : http://127.0.0.1:5176/entretien/nouveau?token=<token> puis /entretien/<id>
# Vérifier dans l'onglet Network : GET /api/vehicules 200, POST /api/entretiens 201,
# PATCH /api/entretiens/{id}/demarrer 200, PATCH .../terminer 200, GET /api/vehicules/{id} 200.
```

Les entretiens créés pendant l’audit portent les descriptions `Audit REST - ...` (ids 15, 16, 17). Aucune donnée n’a été supprimée : les entretiens de test **15, 17, 18 et 19** ont été **archivés** (16 l’était déjà) et la déclaration de panne #7 a été conservée. Véhicule 2 (Clio) : `DISPONIBLE`, 62 500 km ; véhicule 3 (Hilux) : inchangé `EN_MAINTENANCE`, 98 500 km.

---

## 9. Preuves navigateur et état final

**Chromium/Playwright sur `http://127.0.0.1:5176` (JWT ADMINISTRATEUR injecté via `?token=`)** :

| Action UI | Requête réseau observée | Code | Résultat visible |
|---|---|---|---|
| Ouverture `/entretien/nouveau` | `GET /api/vehicules` (×2, `React.StrictMode`) | 200 | `<select>` avec 3 véhicules, dont `67890-B-1 - Renault Clio (62 500 km, DISPONIBLE)` |
| Création via le formulaire | `POST /api/entretiens` | **201** | Redirection `/entretien/18`, statut `Planifie` |
| **Démarrer** | `PATCH /api/entretiens/18/demarrer` | **200** | `En cours`, `Debut` renseigné ; `GET /api/vehicules/2` (hôte) = `EN_MAINTENANCE` |
| **Terminer** | `PATCH /api/entretiens/18/terminer` | **200** | `Termine`, `Fin` renseigné ; `GET /api/vehicules/2` (hôte) = `DISPONIBLE`, 62 500 km |
| Déclarer une panne | `GET /api/vehicules`, `GET /api/chauffeurs`, `POST /api/declarations-panne` | 200 / 200 / **201** | déclaration #7 visible dans la liste |
| Rechargement `/entretien/19` après le cycle `curl.exe` | `GET /api/vehicules/2` | 200 | **`Statut vehicule : DISPONIBLE (62 500 km)`** ; corps `{"data":{"id":2,...,"statut":"DISPONIBLE","kilometrage":62500}}` |

L’onglet témoin `http://localhost:5176/pannes/nouvelle` affichait encore **`502 Bad Gateway`** avec une seule requête documentaire en échec et **aucune requête `/api/`** : c’est la preuve directe de la cause racine (pas de listener, donc pas de React, donc pas de REST).

### Points restants / hypothèses documentées

- Le passage en `EN_MAINTENANCE` intervient au **démarrage** (`demarrer()`) et non à la planification : hypothèse métier déjà documentée dans `module-entretien/README.md`, aucune règle métier modifiée.
- `PROCHE` / `EN_RETARD` conservés (aucun renommage) ; « Mécanicien » = `GESTIONNAIRE`, aucun rôle `MECANICIEN` créé.
- Aucun test `pytest` exécutable (absent de l’hôte et des conteneurs) ; les tests Chauffeur ne sont donc pas prouvés en automatique, mais le flux réel `GET /espace-chauffeur/me/entretiens` a été vérifié à **200**.
- Sous cet environnement, `npm` doit être appelé par son chemin absolu (`C:\Program Files\nodejs\npm.cmd`) ; les commandes nues échouent avec `Shell.NotFoundError`.
- Le serveur Vite lancé sur **5176** pendant l’audit reste volontairement actif : c’est le port attendu. Aucun serveur sur **5177** n’a été démarré, aucun autre processus résiduel laissé ouvert.
