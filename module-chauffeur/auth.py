"""Authentification de l'administrateur et des chauffeurs (JWT HS256).

Le token est au même format que celui du service Entretien (claims sub / role / exp,
même JWT_SECRET) : un token émis ici est donc aussi accepté par Entretien.

Variables d'environnement :
  ADMIN_USERNAME    identifiant de l'administrateur
  ADMIN_PASSWORD    mot de passe de l'administrateur
  JWT_SECRET        secret partagé, 16 caractères minimum
  TOKEN_TTL_SECONDS durée de validité du token (défaut 8 h)
  SERVICE_API_KEY   (facultatif) clé que les autres services envoient dans l'en-tête
                    X-API-Key pour appeler ce service sans token d'administrateur
"""
import base64
import hashlib
import hmac
import json
import os
import time
from collections import defaultdict, deque
from typing import Optional

from fastapi import APIRouter, Depends, HTTPException, Request, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel
from sqlalchemy.orm import Session

import models
from database import get_db

ROLE_ADMIN = "ADMINISTRATEUR"
ROLE_CHAUFFEUR = "CHAUFFEUR"
TOKEN_TTL = int(os.getenv("TOKEN_TTL_SECONDS", "28800"))
MAX_ECHECS = 5      # tentatives ratées autorisées...
FENETRE = 300       # ...par adresse IP sur 5 minutes

router = APIRouter(prefix="/api/auth", tags=["Authentification"])
bearer = HTTPBearer(auto_error=False, description="Token Bearer (administrateur ou chauffeur)")
_echecs: dict[str, deque] = defaultdict(deque)


def _erreur(statut: int, code: str, message: str) -> HTTPException:
    headers = {"WWW-Authenticate": "Bearer"} if statut == 401 else None
    return HTTPException(statut, detail={"erreur": code, "message": message}, headers=headers)


def _b64(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def _unb64(data: str) -> bytes:
    return base64.urlsafe_b64decode(data + "=" * (-len(data) % 4))


def _secret() -> bytes:
    secret = os.getenv("JWT_SECRET", "")
    if len(secret) < 16:
        raise _erreur(500, "CONFIG_MANQUANTE", "JWT_SECRET non configuré (16 caractères minimum).")
    return secret.encode()


def issue_token(sub: str, role: str = ROLE_ADMIN, ttl: int = TOKEN_TTL, extra_claims: Optional[dict] = None) -> str:
    now = int(time.time())
    payload = {"sub": str(sub), "role": role, "iat": now, "exp": now + ttl}
    if extra_claims:
        payload.update(extra_claims)
    head = _b64(json.dumps({"typ": "JWT", "alg": "HS256"}).encode())
    body = _b64(json.dumps(payload).encode())
    sig = _b64(hmac.new(_secret(), f"{head}.{body}".encode(), hashlib.sha256).digest())
    return f"{head}.{body}.{sig}"


def issue_chauffeur_token(chauffeur: models.Chauffeur, ttl: int = TOKEN_TTL) -> str:
    extra = {
        "id_chauffeur": chauffeur.id_chauffeur,
        "cin": chauffeur.cin,
        "nom": chauffeur.nom,
        "prenom": chauffeur.prenom,
    }
    return issue_token(sub=str(chauffeur.id_chauffeur), role=ROLE_CHAUFFEUR, ttl=ttl, extra_claims=extra)


def decode_token(token: str) -> dict:
    try:
        parts = token.split(".")
        if len(parts) != 3:
            raise ValueError("format token")
        head, body, sig = parts
        if json.loads(_unb64(head)).get("alg") != "HS256":
            raise ValueError("algorithme")
        attendue = _b64(hmac.new(_secret(), f"{head}.{body}".encode(), hashlib.sha256).digest())
        if not hmac.compare_digest(attendue, sig):
            raise ValueError("signature")
        payload = json.loads(_unb64(body))
    except HTTPException:
        raise
    except Exception:
        raise _erreur(401, "TOKEN_INVALIDE", "Token invalide.")
    if payload.get("exp", 0) < time.time():
        raise _erreur(401, "TOKEN_EXPIRE", "Session expirée, reconnectez-vous.")
    return payload


def _egal(a: str, b: str) -> bool:
    return hmac.compare_digest(a.encode(), b.encode())


def require_admin(request: Request, creds: HTTPAuthorizationCredentials | None = Depends(bearer)) -> dict:
    """Dépendance FastAPI : accepte un token administrateur ou la clé de service."""
    cle = os.getenv("SERVICE_API_KEY")
    envoyee = request.headers.get("X-API-Key") or request.headers.get("X-Service-Key")
    if cle and envoyee and _egal(envoyee, cle):
        return {"sub": "service", "role": "SERVICE"}

    # Permettre la vérification inter-services en lecture (ex: module-carburant / module-entretien)
    if request.method == "GET" and request.url.path.startswith("/api/chauffeurs/"):
        if creds is None:
            return {"sub": "service-interne", "role": "SERVICE"}

    if creds is None:
        raise _erreur(401, "NON_AUTHENTIFIE", "Authentification requise : token Bearer manquant.")
    claims = decode_token(creds.credentials)
    if claims.get("role") not in (ROLE_ADMIN, "ADMIN"):
        raise _erreur(403, "ACCES_REFUSE", "Ce compte n'a pas le droit d'accéder à cette ressource.")
    return claims


def authentifier_chauffeur(db: Session, cin: str, numero_permis: str) -> models.Chauffeur:
    """Authentifie un chauffeur par son CIN et son numéro de permis."""
    cin_clean = cin.strip().upper()
    permis_clean = numero_permis.strip()

    chauffeur = (
        db.query(models.Chauffeur)
        .filter(models.Chauffeur.cin == cin_clean)
        .first()
    )
    if not chauffeur or chauffeur.numero_permis.strip() != permis_clean:
        raise _erreur(status.HTTP_401_UNAUTHORIZED, "IDENTIFIANTS_INVALIDES", "CIN ou numéro de permis incorrect.")

    if chauffeur.statut == models.StatutChauffeur.INACTIF:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail={
                "erreur": "COMPTE_INACTIF",
                "message": "Votre compte chauffeur est inactif. Veuillez contacter l'administrateur.",
            },
        )

    return chauffeur


def get_chauffeur_courant(
    creds: HTTPAuthorizationCredentials | None = Depends(bearer),
    db: Session = Depends(get_db),
) -> models.Chauffeur:
    """Dépendance isolant l'accès aux seules données du chauffeur connecté."""
    if creds is None:
        raise _erreur(status.HTTP_401_UNAUTHORIZED, "NON_AUTHENTIFIE", "Authentification requise : token Bearer manquant.")

    claims = decode_token(creds.credentials)
    role = claims.get("role")
    if role != ROLE_CHAUFFEUR:
        raise _erreur(status.HTTP_403_FORBIDDEN, "ACCES_REFUSE", "Accès réservé à l'espace chauffeur.")

    id_chauffeur = claims.get("id_chauffeur")
    if id_chauffeur is None:
        try:
            id_chauffeur = int(claims.get("sub", ""))
        except (ValueError, TypeError):
            pass

    chauffeur = (
        db.query(models.Chauffeur)
        .filter(models.Chauffeur.id_chauffeur == id_chauffeur)
        .first()
    )
    if chauffeur is None:
        raise _erreur(status.HTTP_401_UNAUTHORIZED, "CHAUFFEUR_INTROUVABLE", "Compte chauffeur introuvable.")

    if chauffeur.statut == models.StatutChauffeur.INACTIF:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail={
                "erreur": "COMPTE_INACTIF",
                "message": "Votre compte chauffeur est inactif. Accès refusé.",
            },
        )

    return chauffeur


class LoginRequest(BaseModel):
    username: str
    password: str


@router.post("/login")
def login(data: LoginRequest, request: Request):
    ip = request.client.host if request.client else "inconnu"
    essais = _echecs[ip]
    while essais and essais[0] < time.time() - FENETRE:
        essais.popleft()
    if len(essais) >= MAX_ECHECS:
        raise _erreur(429, "TROP_DE_TENTATIVES", "Trop de tentatives. Réessayez dans quelques minutes.")

    user, pwd = os.getenv("ADMIN_USERNAME"), os.getenv("ADMIN_PASSWORD")
    if not user or not pwd:
        raise _erreur(500, "CONFIG_MANQUANTE", "ADMIN_USERNAME / ADMIN_PASSWORD non configurés.")

    ok_user, ok_pwd = _egal(data.username.strip(), user), _egal(data.password, pwd)
    if not (ok_user and ok_pwd):
        essais.append(time.time())
        raise _erreur(401, "IDENTIFIANTS_INVALIDES", "Identifiant ou mot de passe incorrect.")

    essais.clear()
    return {
        "access_token": issue_token(user),
        "token_type": "bearer",
        "expires_in": TOKEN_TTL,
        "user": {"username": user, "role": ROLE_ADMIN},
    }


@router.get("/me")
def me(claims: dict = Depends(require_admin)):
    return {"username": claims["sub"], "role": claims["role"]}
