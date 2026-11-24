-- Bootstrap d'une base VIERGE uniquement (volume MySQL initialise une seule fois).
-- Le schema est source depuis la migration versionnee du service Vehicule :
-- aucune definition de table n'est dupliquee ici.
CREATE DATABASE IF NOT EXISTS gestion_flotte
    CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE gestion_flotte;

SOURCE /docker-entrypoint-migrations/vehicule/001_create_vehicules.sql;

-- Donnees de demonstration : INSERT IGNORE = jamais ecraser une donnee existante.
INSERT IGNORE INTO vehicules
    (immatriculation, marque, modele, annee, kilometrage, type_carburant, statut)
VALUES
    ('12345-A-6', 'Dacia', 'Logan', 2021, 45200, 'Diesel', 'DISPONIBLE'),
    ('67890-B-1', 'Renault', 'Clio', 2020, 62300, 'Essence', 'DISPONIBLE'),
    ('24680-C-3', 'Toyota', 'Hilux', 2019, 98500, 'Diesel', 'EN_MAINTENANCE');
