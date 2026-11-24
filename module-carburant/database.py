import os
from urllib.parse import quote_plus

from sqlalchemy import create_engine
from sqlalchemy.orm import declarative_base, sessionmaker

# Les quatre services utilisent les memes parametres (voir docker-compose.yml).
# DATABASE_URL reste accepte comme surcharge explicite (tests locaux, Alembic).
DB_HOST = os.getenv("DB_HOST", "db-commune")
DB_PORT = os.getenv("DB_PORT", "3306")
DB_NAME = os.getenv("DB_NAME", "gestion_flotte")
DB_USER = os.getenv("DB_USER", "root")
DB_PASSWORD = os.getenv("DB_PASSWORD", os.getenv("DB_ROOT_PASSWORD", "rootpassword"))

DATABASE_URL = os.getenv("DATABASE_URL") or (
    f"mysql+pymysql://{quote_plus(DB_USER)}:{quote_plus(DB_PASSWORD)}"
    f"@{DB_HOST}:{DB_PORT}/{DB_NAME}"
)

# check_same_thread n'existe que pour SQLite : on ne le met que dans ce cas.
connect_args = {"check_same_thread": False} if DATABASE_URL.startswith("sqlite") else {}

engine = create_engine(
    DATABASE_URL,
    connect_args=connect_args,
    pool_pre_ping=True,  # evite les connexions MySQL mortes
)
SessionLocal = sessionmaker(bind=engine, autocommit=False, autoflush=False)
Base = declarative_base()


def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()
