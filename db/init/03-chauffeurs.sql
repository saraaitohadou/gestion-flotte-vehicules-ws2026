CREATE DATABASE IF NOT EXISTS gestion_flotte;
USE gestion_flotte;

CREATE TABLE IF NOT EXISTS chauffeurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    cin VARCHAR(20) NOT NULL UNIQUE,
    numero_permis VARCHAR(50) NOT NULL UNIQUE,
    telephone VARCHAR(20),
    vehicule_id INT NULL,
    statut VARCHAR(20) NOT NULL DEFAULT 'ACTIF',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO chauffeurs (nom, prenom, cin, numero_permis, telephone, vehicule_id, statut)
VALUES ('Alami', 'Youssef', 'J123456', 'PERM-88412', '0661000001', 1, 'ACTIF');

INSERT INTO chauffeurs (nom, prenom, cin, numero_permis, telephone, vehicule_id, statut)
VALUES ('Bennani', 'Salma', 'J654321', 'PERM-99201', '0661000002', 2, 'ACTIF');

INSERT INTO chauffeurs (nom, prenom, cin, numero_permis, telephone, vehicule_id, statut)
VALUES ('Mansouri', 'Karim', 'J789012', 'PERM-44109', '0661000003', NULL, 'ACTIF');

INSERT INTO chauffeurs (nom, prenom, cin, numero_permis, telephone, vehicule_id, statut)
VALUES ('Ouafi', 'Nadia', 'J456789', 'PERM-33018', '0661000004', 1, 'INACTIF');