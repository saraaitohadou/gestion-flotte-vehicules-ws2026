import time
import jwt
import pytest

JWT_SECRET = "flotte_secret_key_2026_secure_98765"
ALGORITHM = "HS256"


def create_token(sub: str = "admin", role: str = "ADMINISTRATEUR", ttl: int = 3600) -> str:
    now = int(time.time())
    payload = {"sub": sub, "role": role, "iat": now, "exp": now + ttl}
    return jwt.encode(payload, JWT_SECRET, algorithm=ALGORITHM)


def decode_token(token: str) -> dict:
    return jwt.decode(token, JWT_SECRET, algorithms=[ALGORITHM])


def extract_vehicles(payload) -> list[dict]:
    if isinstance(payload, list):
        return payload
    if isinstance(payload, dict):
        for key in ("data", "vehicules", "items"):
            if isinstance(payload.get(key), list):
                return payload[key]
    return []


def test_valid_jwt_roundtrip():
    token = create_token(sub="sara", role="ADMINISTRATEUR")
    decoded = decode_token(token)
    assert decoded["sub"] == "sara"
    assert decoded["role"] == "ADMINISTRATEUR"


def test_invalid_jwt_rejected():
    token = create_token(sub="sara")
    with pytest.raises(jwt.InvalidSignatureError):
        jwt.decode(token, "mauvaise_cle_secrete", algorithms=[ALGORITHM])


def test_extract_vehicles_formats():
    sample = [
        {"id": 2, "immatriculation": "67890-B-1", "statut": "DISPONIBLE"},
        {"id": 3, "immatriculation": "24680-C-3", "statut": "DISPONIBLE"},
    ]
    assert len(extract_vehicles(sample)) == 2
    assert len(extract_vehicles({"data": sample})) == 2
    assert len(extract_vehicles({"vehicules": sample})) == 2
    assert extract_vehicles({}) == []