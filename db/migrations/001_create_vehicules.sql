-- =============================================================================
-- Migration versionnee - service VEHICULE (proprietaire de la table vehicules)
-- Base commune : gestion_flotte
-- Non destructive : CREATION UNIQUEMENT SI LA TABLE N'EXISTE PAS.
-- Aucune donnee n'est modifiee ni supprimee lors d'une re-execution.
-- =============================================================================

CREATE TABLE IF NOT EXISTS vehicules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    immatriculation VARCHAR(20) NOT NULL UNIQUE,
    marque VARCHAR(50) NOT NULL,
    modele VARCHAR(50) NOT NULL,
    annee INT NOT NULL,
    kilometrage DOUBLE NOT NULL DEFAULT 0,
    type_carburant VARCHAR(20) NOT NULL,
    statut VARCHAR(20) NOT NULL DEFAULT 'DISPONIBLE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
