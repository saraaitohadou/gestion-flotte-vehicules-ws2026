-- Bootstrap d'une base VIERGE uniquement (volume MySQL initialise une seule fois).
-- Le schema est source depuis la migration versionnee du service Chauffeur :
-- aucune definition de table n'est dupliquee ici.
USE gestion_flotte;

SOURCE /docker-entrypoint-migrations/chauffeur/001_create_chauffeurs.sql;

-- Donnees de demonstration : INSERT IGNORE = jamais ecraser une donnee existante.
INSERT IGNORE INTO chauffeurs
    (cin, nom, prenom, telephone, numero_permis, categorie_permis,
     date_expiration_permis, statut, vehicule_id)
VALUES
    ('J123456', 'Alami', 'Youssef', '0661000001', 'PERM-88412', 'B', '2028-06-30', 'DISPONIBLE', 1),
    ('J654321', 'Bennani', 'Salma', '0661000002', 'PERM-99201', 'B', '2027-11-15', 'EN_MISSION', 2),
    ('J789012', 'Mansouri', 'Karim', '0661000003', 'PERM-44109', 'C', '2029-03-20', 'DISPONIBLE', NULL),
    ('J456789', 'Ouafi', 'Nadia', '0661000004', 'PERM-33018', 'B', '2026-12-10', 'INACTIF', 1);
