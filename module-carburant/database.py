import os

from sqlalchemy import create_engine
from sqlalchemy.orm import declarative_base, sessionmaker

# En Docker, la valeur vient du docker-compose (hote "db").
# En local sans Docker, on tombe sur ce defaut (meme convention que Chauffeur).
DATABASE_URL = os.getenv(
    "DATABASE_URL",
    "mysql+pymysql://root:rootpassword@localhost:3306/flotte_db",
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
