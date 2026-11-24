# Rapport de test End-to-End UI — Modules Véhicule / Chauffeur / Carburant / Entretien

**Date :** 24/11/2026  
**Branche :** `feat/front-login` (après fusion de `origin/main`, commit de merge `7c8cd91`)  
**Base de données :** `gestion_flotte` (base commune, MySQL dans `db-commune`)  
**Comptes utilisés :** `admin` / `200383` (portail central `http://localhost:5174/connexion`)  
**Interfaces testées :**

| Module | URL |
|---|---|
| Véhicule | `http://127.0.0.1:5173/vehicules` |
| Chauffeur | `http://127.0.0.1:5174/chauffeurs` |
| Entretien | `http://127.0.0.1:5176/...` |
| Carburant (UI statique/React servie par le service) | `http://127.0.0.1:8003/ui/` |

> Les scénarios ont été exécutés depuis les interfaces, comme un utilisateur. Les appels API/SQL n’ont servi qu’au diagnostic et à la vérification finale, jamais à créer les données de test.

---

## 1. Véhicule — consultation et synchronisation de statut

**Objectif :** vérifier que le statut et le kilométrage d’un véhicule reflètent les opérations d’Entretien.

### Étapes UI
1. Ouvrir `http://127.0.0.1:5173/vehicules`.
2. Repérer le véhicule `#1 — 12345-A-6 (Dacia Logan)`.
3. Après démarrage de l’entretien #26, recharger la page : le statut passe à `EN_MAINTENANCE`.
4. Après terminaison de l’entretien #26, recharger la page : le statut repasse à `DISPONIBLE`.

### Attendu vs réel
- **Attendu :** synchronisation Véhicule ↔ Entretien, kilométrage inchangé à `45 400 km`.
- **Réel :** `DISPONIBLE` observé après terminaison ; `45 400 km` affiché ; 3 véhicules présents.

### Interfaces concernées
`Véhicule`, `Entretien`.

### Erreurs / cause probable si désynchronisation
Aucune désynchronisation observée. La cause probable d’un échec aurait été une écriture du statut dans une base différente ; la base commune `gestion_flotte` a précisément supprimé ce risque.

### Captures
- Avant : `e2e-captures/avant-vehicule-os.png`
- Après démarrage : `e2e-captures/apres-demarrage-vehicule.png`
- Après fin : `e2e-captures/apres-fin-vehicule.png`
- Vérification finale : `e2e-captures/apres-verification-vehicule.png`

**Résultat : RÉUSSI**

---

## 2. Entretien — création, modification, démarrage, terminaison

**Objectif :** cycle complet d’un entretien sur le véhicule `#1` et répercussion dans le module Véhicule.

### Données de test créées
- Entretien **#26**
- Véhicule : `#1 — 12345-A-6 (Dacia Logan)`
- Date : `24/11/2026`
- Kilométrage : `45 400 km`
- Type : `PREVENTIF`
- Catégorie : `VIDANGE`
- Coût : `250,00 MAD`
- Description initiale : `E2E-UI-TEST — vidange de contrôle véhicule 1.`
- Description après modification : `E2E-UI-TEST — vidange de contrôle véhicule 1. — MODIFIÉ`

### Étapes UI
1. Créer l’entretien via le formulaire d’Entretien.
2. Ouvrir la fiche #26, modifier la description, enregistrer.
3. Cliquer sur `Démarrer` : statut `EN_MAINTENANCE`, visible dans Véhicule.
4. Cliquer sur `Terminer` : statut `TERMINÉ`, visible dans Véhicule en `DISPONIBLE`.
5. Recharger les pages : persistance confirmée.

### Attendu vs réel
- **Attendu :** création, modification, démarrage et terminaison sans perte de données ni incohérence de statut.
- **Réel :** fiche #26 affiche `Preventif / Terminé`, description modifiée, suivi `Debut 24/11/2026 13:57:35`, `Fin 24/11/2026 13:58:20`, véhicule `DISPONIBLE (45 400 km)`.

### Interfaces concernées
`Entretien`, `Véhicule`.

### Erreurs / cause probable si désynchronisation
Aucune sur ce cycle. Le redémarrage du serveur de développement Entretien (port 5176) avait provoqué un `502 Bad Gateway` temporaire ; après relance de Vite, la fiche #26 était intacte. Ce n’est pas un défaut applicatif.

### Captures
- Avant formulaire : `e2e-captures/avant-entretien-formulaire.png`
- Après création : `e2e-captures/apres-entretien-creation.png`
- Après modification : `e2e-captures/apres-entretien-modification.png`
- Après démarrage : `e2e-captures/apres-demarrage-vehicule.png`
- Après fin : `e2e-captures/apres-fin-entretien.png`
- Vérification finale : `e2e-captures/apres-verification-entretien.png`

**Résultat : RÉUSSI**

---

## 3. Chauffeur — modification d’un chauffeur existant

**Objectif :** modifier un chauffeur via l’UI et vérifier la persistance.

### Données modifiées
- Chauffeur `#3 — Karim Mansouri`
- Téléphone : `0661000003` → `0670000000`

### Étapes UI
1. Ouvrir `http://127.0.0.1:5174/chauffeurs/3/modifier`.
2. Remplacer le téléphone.
3. Cliquer sur `Enregistrer les modifications`.
4. La fiche de détail s’affiche avec le nouveau numéro.

### Attendu vs réel
- **Attendu :** téléphone mis à jour et visible immédiatement.
- **Réel :** fiche `Karim Mansouri` affiche `Téléphone : 0670000000`.

### Interfaces concernées
`Chauffeur`, `Carburant` (liste de référence des chauffeurs, qui affiche bien `Mansouri Karim`).

### Erreurs / cause probable si désynchronisation
Aucune. Note : le champ étant contrôlé par React, une saisie directe sans remplacement complet peut concaténer l’ancienne valeur ; le test a utilisé un remplacement explicite puis vérification du champ avant soumission.

### Captures
- Avant : `e2e-captures/avant-modification-chauffeur-form.png`
- Après : `e2e-captures/apres-modification-chauffeur.png`

**Résultat : RÉUSSI**

---

## 4. Carburant — création d’un plein et vérification des statistiques

**Objectif :** créer un plein via l’UI React du service Carburant avec des références Véhicule/Chauffeur chargées depuis les autres services.

### Données de test créées
- Plein **#3**
- Véhicule : `#1 — 12345-A-6`
- Chauffeur : laissé vide (voir erreur métier ci-dessous)
- Date : `24/11/2026`
- Station : `Station E2E`
- Carburant : `Diesel`
- Quantité : `45,0 L`
- Prix : `15,50 MAD/L`
- Montant calculé : `697,50 MAD`
- Kilométrage : `45 500 km`
- Plein complet : coché

### Étapes UI
1. Se connecter au portail central, puis ouvrir `http://127.0.0.1:8003/ui/#/nouveau` avec le token.
2. Vérifier les listes déroulantes : 3 véhicules et 3 chauffeurs remontés.
3. Remplir le formulaire, vérifier le montant calculé puis enregistrer.
4. Vérifier la liste `Pleins` : `1 résultat`, ligne du 24/11/2026.
5. Ouvrir `Statistiques`, sélectionner `Véhicule 1`, cliquer sur `Calculer`.
6. Revenir au tableau de bord et vérifier `697,50 MAD / 45,0 L`.

### Attendu vs réel
- **Attendu :** enregistrement du plein, montant cohérent, statistiques mises à jour.
- **Réel :** plein visible dans la liste ; statistiques affichent `1 plein`, `45,0 L`, `697,50 MAD` ; tableau de bord affiche les mêmes valeurs. `Consommation moyenne` reste `—` car il n’existe qu’un seul plein complet de référence (comportement attendu).

### Interfaces concernées
`Carburant`, `Véhicule`, `Chauffeur` (références), `Entretien` (cohérence du kilométrage du véhicule #1).

### Erreurs / cause probable si désynchronisation
- Erreur métier rencontrée : `Ce chauffeur n'est pas affecté à ce véhicule` quand `Mansouri Karim` était sélectionné pour le véhicule #1. Cause : contrôle applicatif `verifier_references()` — Karim n’a pas de véhicule assigné. Le test a poursuivi avec `Chauffeur = Aucun`, champ facultatif. Ce n’est pas un bug de synchronisation.
- Correctif appliqué pendant le test : bust de cache du frontend statique (`app.js?v=2` → `app.js?v=3`) car le navigateur servait une ancienne version de `chargerReferences()` qui dérivait les listes des pleins existants. Le service a été reconstruit. Ce changement est versionné dans `module-carburant/static/index.html`.
- Observation mineure : le tableau de bord React affiche brièvement des données de démonstration avant que l’API réponde ; elles sont remplacées ensuite par les données réelles. Aucun impact fonctionnel.

### Captures
- Avant : `e2e-captures/avant-carburant.png`
- Avant soumission du plein : `e2e-captures/avant-creation-plein.png`
- Après création : `e2e-captures/apres-creation-plein.png`
- Après statistiques : `e2e-captures/apres-stats-carburant.png`
- Après tableau de bord : `e2e-captures/apres-dashboard-carburant.png`

**Résultat : RÉUSSI**

---

## 5. Entretien — formulaire « Déclarer une panne » (sous-scénario)

**Objectif :** vérifier que la liste des chauffeurs est alimentée dans le formulaire de déclaration de panne.

### Étapes UI
1. Ouvrir `http://127.0.0.1:5176/pannes/nouvelle`.
2. Observer la liste `Véhicule` : 3 véhicules affichés.
3. Observer la liste `Chauffeur`.

### Attendu vs réel
- **Attendu :** 3 chauffeurs proposés, comme sur l’API `GET /api/chauffeurs`.
- **Réel :** `Aucun chauffeur disponible` affiché, alors que l’API renvoie bien 3 chauffeurs et que les autres modules les affichent.

### Interfaces concernées
`Entretien` (frontend), `Chauffeur` (API sources).

### Erreurs / cause probable
Cause probable confirmée par lecture du code : `frontend-entretien/src/pages/DeclarerPanne.jsx` ligne 47 fait `setChauffeurs(r.data ?? [])` alors que `api.chauffeurs()` renvoie directement un tableau. Le tableau résultant est donc vide. Ce défaut est local au formulaire et n’affecte ni les entretiens créés ni la synchronisation inter-services. Aucun correctif n’a été appliqué car il sortait du périmètre de la résolution de conflit.

### Capture
- `e2e-captures/echec-liste-chauffeurs-panne.png`

**Résultat : ÉCHOUÉ**

---

## Tableau final de validation

| # | Scénario | Module(s) | Statut |
|---|---|---|---|
| 1 | Consultation des véhicules et statut `DISPONIBLE` après entretien | Véhicule, Entretien | **RÉUSSI** |
| 2 | Cycle complet entretien #26 (créer / modifier / démarrer / terminer) | Entretien, Véhicule | **RÉUSSI** |
| 3 | Modification du téléphone de Karim Mansouri (#3) | Chauffeur, Carburant | **RÉUSSI** |
| 4 | Création d’un plein avec références inter-services | Carburant, Véhicule, Chauffeur | **RÉUSSI** |
| 5 | Statistiques et tableau de bord Carburant après plein | Carburant | **RÉUSSI** |
| 6 | Liste des chauffeurs dans « Déclarer une panne » | Entretien, Chauffeur | **ÉCHOUÉ** |
| 7 | Suppression d’un plein / d’un entretien | Carburant, Entretien | **NON TESTÉ** (non autorisé : pas de suppression de données) |
| 8 | Export CSV des pleins | Carburant | **NON TESTÉ** |
| 9 | Rôles Chauffeur et Gestionnaire (hors admin) | Portail, tous | **NON TESTÉ** |
| 10 | Seuil d’alerte de consommation (> 30 %) | Carburant | **NON TESTÉ** (nécessite plusieurs pleins) |

---

## Données créées / modifiées pendant le test

| Type | Détail |
|---|---|
| Entretien | #26 sur véhicule #1, `TERMINÉ`, description `— MODIFIÉ` |
| Chauffeur | #3 Karim Mansouri, téléphone `0670000000` |
| Plein | #3 sur véhicule #1, 45 L, 15,50 MAD/L, 45 500 km, `Station E2E` |
| Code | `module-carburant/static/index.html` : `app.js?v=3` (bust de cache) |
| Git | Merge de `origin/main` dans `feat/front-login` commité `7c8cd91` et poussé |

Aucune donnée préexistante n’a été supprimée. Aucun `migrate:fresh`, `reset --hard`, `push --force` ni `git clean` n’a été utilisé.
