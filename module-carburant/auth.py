import base64
import hashlib
import hmac
import json
import os
import time

from fastapi import Depends, HTTPException, Request, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer


ROLE_ADMIN = "ADMINISTRATEUR"
ROLE_GESTIONNAIRE = "GESTIONNAIRE"
ROLE_CHAUFFEUR = "CHAUFFEUR"
ROLES_AUTORISES = {ROLE_ADMIN, ROLE_GESTIONNAIRE, ROLE_CHAUFFEUR, "SERVICE"}

bearer = HTTPBearer(auto_error=False, description="Token Bearer JWT")


def _b64(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def _unb64(data: str) -> bytes:
    return base64.urlsafe_b64decode(data + "=" * (-len(data) % 4))


def _erreur(statut: int, code: str, message: str) -> HTTPException:
    headers = {"WWW-Authenticate": "Bearer"} if statut == status.HTTP_401_UNAUTHORIZED else None
    return HTTPException(statut, detail={"erreur": code, "message": message}, headers=headers)


def _secret() -> bytes:
    secret = os.getenv("JWT_SECRET", "")
    if len(secret) < 16:
        raise _erreur(500, "CONFIG_MANQUANTE", "JWT_SECRET non configure (16 caracteres minimum).")
    return secret.encode()


def decode_token(token: str) -> dict:
    try:
        parts = token.split(".")
        if len(parts) != 3:
            raise ValueError("format")
        head, body, sig = parts
        header = json.loads(_unb64(head))
        if header.get("alg") != "HS256":
            raise ValueError("algorithme")
        attendue = _b64(hmac.new(_secret(), f"{head}.{body}".encode(), hashlib.sha256).digest())
        if not hmac.compare_digest(attendue, sig):
            raise ValueError("signature")
        payload = json.loads(_unb64(body))
    except HTTPException:
        raise
    except Exception:
        raise _erreur(401, "TOKEN_INVALIDE", "Token invalide.")

    expiration = payload.get("exp")
    if expiration is None:
        raise _erreur(401, "TOKEN_INVALIDE", "Token sans expiration.")
    try:
        expire = float(expiration)
    except (TypeError, ValueError):
        raise _erreur(401, "TOKEN_INVALIDE", "Expiration invalide.")
    if expire <= time.time():
        raise _erreur(401, "TOKEN_EXPIRE", "Session expiree, reconnectez-vous.")
    return payload


def issue_token_admin(sub: str = "admin", ttl: int = 3600) -> str:
    """Utile aux tests; la production utilise les jetons emis par Chauffeur."""
    now = int(time.time())
    payload = {"sub": sub, "role": ROLE_ADMIN, "exp": now + ttl}
    head = _b64(json.dumps({"typ": "JWT", "alg": "HS256"}).encode())
    body = _b64(json.dumps(payload).encode())
    sig = _b64(hmac.new(_secret(), f"{head}.{body}".encode(), hashlib.sha256).digest())
    return f"{head}.{body}.{sig}"


def require_auth(
    request: Request,
    creds: HTTPAuthorizationCredentials | None = Depends(bearer),
) -> dict:
    cle = os.getenv("SERVICE_API_KEY", "")
    envoyee = request.headers.get("X-API-Key") or request.headers.get("X-Service-Key")
    if cle and envoyee and hmac.compare_digest(envoyee, cle):
        return {"sub": "service", "role": "SERVICE"}

    if creds is None:
        authorization = request.headers.get("Authorization", "")
        if authorization.lower().startswith("bearer ") and authorization[7:].strip():
            creds = HTTPAuthorizationCredentials(scheme="Bearer", credentials=authorization[7:].strip())
    if creds is None:
        raise _erreur(401, "NON_AUTHENTIFIE", "Authentification requise : token Bearer ou cle de service.")

    claims = decode_token(creds.credentials)
    role = claims.get("role")
    if role not in ROLES_AUTORISES:
        raise _erreur(403, "ACCES_REFUSE", "Role non autorise.")
    return claims
