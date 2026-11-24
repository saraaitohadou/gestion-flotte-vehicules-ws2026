-- =============================================================================
-- Migration versionnee SQL - service CHAUFFEUR (proprietaire de chauffeurs)
-- Base commune : gestion_flotte
-- Non destructive : CREATION UNIQUEMENT SI LA TABLE N'EXISTE PAS.
-- Reproduit exactement le schema cree historiquement par SQLAlchemy create_all(),
-- sans ajouter, renommer ou supprimer de colonne sur une table existante.
-- =============================================================================

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
    vehicule_id INT NULL,
    KEY ix_chauffeurs_id_chauffeur (id_chauffeur),
    KEY ix_chauffeurs_vehicule_id (vehicule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
