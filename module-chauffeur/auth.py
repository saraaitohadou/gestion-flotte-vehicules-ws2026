"""Authentification de l'administrateur (JWT HS256).

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

from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel

ROLE_ADMIN = "ADMINISTRATEUR"
TOKEN_TTL = int(os.getenv("TOKEN_TTL_SECONDS", "28800"))
MAX_ECHECS = 5      # tentatives ratées autorisées...
FENETRE = 300       # ...par adresse IP sur 5 minutes

router = APIRouter(prefix="/api/auth", tags=["Authentification"])
bearer = HTTPBearer(auto_error=False, description="Token obtenu avec POST /api/auth/login")
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


def issue_token(sub: str, role: str = ROLE_ADMIN, ttl: int = TOKEN_TTL) -> str:
    now = int(time.time())
    head = _b64(json.dumps({"typ": "JWT", "alg": "HS256"}).encode())
    body = _b64(json.dumps({"sub": sub, "role": role, "iat": now, "exp": now + ttl}).encode())
    sig = _b64(hmac.new(_secret(), f"{head}.{body}".encode(), hashlib.sha256).digest())
    return f"{head}.{body}.{sig}"


def decode_token(token: str) -> dict:
    try:
        head, body, sig = token.split(".")
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
    envoyee = request.headers.get("X-API-Key")
    if cle and envoyee and _egal(envoyee, cle):
        return {"sub": "service", "role": "SERVICE"}

    if creds is None:
        raise _erreur(401, "NON_AUTHENTIFIE", "Authentification requise : token Bearer manquant.")
    claims = decode_token(creds.credentials)
    if claims.get("role") not in (ROLE_ADMIN, "ADMIN"):
        raise _erreur(403, "ACCES_REFUSE", "Ce compte n'a pas le droit d'accéder à cette ressource.")
    return claims


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

    # Les deux comparaisons sont toujours faites (pas de court-circuit)
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
