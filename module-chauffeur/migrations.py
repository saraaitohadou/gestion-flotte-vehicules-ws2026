"""Apply les migrations SQL versionnees du service Chauffeur.

- MySQL : fichiers `db/migrations/*.sql`, idempotents, traces dans `migrations_sql`.
- SQLite : repli sur `Base.metadata.create_all()` uniquement pour les tests (conftest.py).
"""
import os

from sqlalchemy import text

from database import engine

MIGRATIONS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "db", "migrations")


def _instructions(sql: str) -> list[str]:
    """Retire les commentaires SQL et separe les instructions sur ';'."""
    lignes = []
    for ligne in sql.splitlines():
        propre = ligne.strip()
        if propre and not propre.startswith("--"):
            lignes.append(propre)
    return [part.strip() for part in " ".join(lignes).split(";") if part.strip()]


def appliquer_migrations_sql() -> None:
    """Cree les tables manquantes sans jamais toucher aux tables ni aux donnees existantes."""
    with engine.begin() as connexion:
        connexion.execute(text(
            "CREATE TABLE IF NOT EXISTS migrations_sql ("
            "nom VARCHAR(255) NOT NULL PRIMARY KEY, "
            "appliquee_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ))

        for nom in sorted(os.listdir(MIGRATIONS_DIR)):
            if not nom.endswith(".sql"):
                continue
            deja = connexion.execute(
                text("SELECT 1 FROM migrations_sql WHERE nom = :nom"), {"nom": nom}
            ).first()
            if deja:
                continue

            with open(os.path.join(MIGRATIONS_DIR, nom), encoding="utf-8") as fichier:
                instructions = _instructions(fichier.read())
            for instruction in instructions:
                connexion.execute(text(instruction))

            connexion.execute(
                text("INSERT INTO migrations_sql (nom) VALUES (:nom)"), {"nom": nom}
            )


def appliquer_migrations() -> None:
    """Point d'entree appele au demarrage de l'API Chauffeur."""
    if engine.url.get_backend_name() == "sqlite":
        # Les tests unitaires utilisent SQLite en memoire : le DDL MySQL
        # (ENUM, AUTO_INCREMENT) n'est pas applicable, on garde le comportement historique.
        import models

        models.Base.metadata.create_all(bind=engine)
        return
    appliquer_migrations_sql()
