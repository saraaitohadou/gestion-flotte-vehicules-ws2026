import os
from sqlalchemy import create_engine
from dotenv import load_dotenv
from sqlalchemy.orm import sessionmaker,declarative_base


DATABASE_URL = os.getenv(

    "DATABASE_URL",
    "mysql+pymysql://root:rootpassword@localhost:3306/flotte_db"
) 

engine = create_engine(DATABASE_URL, pool_pre_ping=True)

SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)

Base = declarative_base()

def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()