CREATE DATABASE IF NOT EXISTS vehicule_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vehicule_db;

CREATE TABLE IF NOT EXISTS vehicules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    immatriculation VARCHAR(20) NOT NULL UNIQUE,
    marque VARCHAR(50) NOT NULL,
    modele VARCHAR(50) NOT NULL,
    annee INT NOT NULL,
    kilometrage DOUBLE NOT NULL DEFAULT 0,
    type_carburant VARCHAR(20) NOT NULL,
    statut VARCHAR(20) NOT NULL DEFAULT 'DISPONIBLE'
);

INSERT INTO vehicules (immatriculation, marque, modele, annee, kilometrage, type_carburant, statut) VALUES
('12345-A-6', 'Dacia', 'Logan', 2021, 45200, 'Diesel', 'DISPONIBLE'),
('67890-B-1', 'Renault', 'Clio', 2020, 62300, 'Essence', 'EN_MISSION'),
('24680-C-3', 'Toyota', 'Hilux', 2019, 98500, 'Diesel', 'EN_MAINTENANCE');