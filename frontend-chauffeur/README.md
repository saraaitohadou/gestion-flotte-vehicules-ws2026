# Frontend du module Chauffeur (React + Vite)

## Les 5 interfaces

| Page | Route | Rôle |
|---|---|---|
| Tableau de bord | `/` | Compteurs, répartition par statut, permis à renouveler, derniers ajouts |
| Liste | `/chauffeurs` | Recherche, filtres (statut, catégorie, état du permis), pagination, suppression |
| Ajout | `/chauffeurs/nouveau` | Formulaire avec validation (CIN, téléphone, permis) |
| Modification | `/chauffeurs/:id/modifier` | Même formulaire, pré-rempli |
| Profil | `/chauffeurs/:id` | Carte du permis, changement de statut, éligibilité, compatibilité véhicule |

## Lancer

```bash
npm install
npm run dev        # http://localhost:5173
```

Le service Chauffeur doit tourner sur `http://localhost:8000`. En dev, Vite relaie `/api/*` vers cette adresse (voir `vite.config.js`).
Pour une autre adresse : `VITE_PROXY_TARGET=http://chauffeur-service:8000 npm run dev`.

## En production (CORS)

Si le frontend n'est pas servi derrière le même domaine que l'API, ajoutez dans `module-chauffeur/main.py` :

```python
from fastapi.middleware.cors import CORSMiddleware

app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:5173"],   # adresse du frontend
    allow_methods=["*"],
    allow_headers=["*"],
)
```

puis définissez `VITE_API_URL=https://votre-domaine/api/chauffeurs` avant `npm run build`.

## Notes

- La suppression est **logique** : le chauffeur passe à INACTIF et disparaît de la liste.
  Si on recrée un chauffeur avec le CIN d'un chauffeur désactivé, le formulaire propose de le réactiver.
- Un chauffeur EN_MISSION ne peut pas être supprimé (règle de l'API) : le bouton est désactivé.
- L'API n'a pas de paramètre de recherche : la recherche texte est faite côté navigateur sur les 200 premiers
  chauffeurs actifs (limite max de l'API). Au-delà, ajoutez un paramètre `q` dans `lister_chauffeurs`.
