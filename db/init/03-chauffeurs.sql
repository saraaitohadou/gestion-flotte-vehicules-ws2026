CREATE DATABASE IF NOT EXISTS gestion_flotte;
USE gestion_flotte;

CREATE TABLE IF NOT EXISTS chauffeurs (
    id_chauffeur INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cin VARCHAR(20) NOT NULL UNIQUE,
    nom VARCHAR(50) NOT NULL,
    prenom VARCHAR(50) NOT NULL,
    telephone VARCHAR(20) NULL,
    numero_permis VARCHAR(30) NOT NULL UNIQUE,
    categorie_permis VARCHAR(10) NOT NULL,
    date_expiration_permis DATE NOT NULL,
    statut ENUM('DISPONIBLE', 'EN_MISSION', 'EN_CONGE', 'INACTIF') NOT NULL DEFAULT 'DISPONIBLE',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    vehicule_id INT NULL
);

INSERT INTO chauffeurs (
    cin,
    nom,
    prenom,
    telephone,
    numero_permis,
    categorie_permis,
    date_expiration_permis,
    statut,
    vehicule_id
)
VALUES
    ('J123456', 'Alami', 'Youssef', '0661000001', 'PERM-88412', 'B', '2028-06-30', 'DISPONIBLE', 1),
    ('J654321', 'Bennani', 'Salma', '0661000002', 'PERM-99201', 'B', '2027-11-15', 'EN_MISSION', 2),
    ('J789012', 'Mansouri', 'Karim', '0661000003', 'PERM-44109', 'C', '2029-03-20', 'DISPONIBLE', NULL),
    ('J456789', 'Ouafi', 'Nadia', '0661000004', 'PERM-33018', 'B', '2026-12-10', 'INACTIF', 1)