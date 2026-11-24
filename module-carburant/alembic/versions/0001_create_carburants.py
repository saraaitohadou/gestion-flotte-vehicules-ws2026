"""Creation de la table carburants (base commune gestion_flotte).

Revision ID: 0001_create_carburants
Revises:
Create Date: 2026-11-24

Non destructive : la table n'est creee que si elle n'existe pas deja.
Le downgrade est volontairement neutralise pour ne jamais supprimer de pleins.
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy import inspect

revision = "0001_create_carburants"
down_revision = None
branch_labels = None
depends_on = None


def upgrade() -> None:
    connexion = op.get_bind()
    if "carburants" in inspect(connexion).get_table_names():
        return

    op.create_table(
        "carburants",
        sa.Column("id", sa.Integer(), autoincrement=True, nullable=False),
        sa.Column("vehicule_id", sa.Integer(), nullable=False),
        sa.Column("chauffeur_id", sa.Integer(), nullable=True),
        sa.Column("date_plein", sa.Date(), nullable=False),
        sa.Column("quantite_litres", sa.Float(), nullable=False),
        sa.Column("prix_litre", sa.Float(), nullable=False),
        sa.Column("montant_total", sa.Float(), nullable=False),
        sa.Column("kilometrage", sa.Integer(), nullable=False),
        sa.Column("type_carburant", sa.String(length=20), nullable=False),
        sa.Column("station", sa.String(length=100), nullable=True),
        sa.Column("plein_complet", sa.Boolean(), nullable=False),
        sa.Column("consommation_l_100km", sa.Float(), nullable=True),
        sa.Column("anomalie", sa.Boolean(), nullable=False),
        sa.Column("message_anomalie", sa.String(length=255), nullable=True),
        sa.PrimaryKeyConstraint("id"),
        mysql_charset="utf8mb4",
        mysql_collate="utf8mb4_0900_ai_ci",
    )
    op.create_index("ix_carburants_id", "carburants", ["id"], unique=False)
    op.create_index("ix_carburants_vehicule_id", "carburants", ["vehicule_id"], unique=False)
    op.create_index("ix_carburants_chauffeur_id", "carburants", ["chauffeur_id"], unique=False)
    op.create_index("ix_carburants_date_plein", "carburants", ["date_plein"], unique=False)


def downgrade() -> None:
    # Securite donnees : aucun DROP TABLE n'est autorise sur la base commune.
    pass
