USE gestion_flotte;

CREATE TABLE IF NOT EXISTS carburants (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vehicule_id INT NOT NULL,
    chauffeur_id INT NULL,
    date_plein DATE NOT NULL,
    quantite_litres FLOAT NOT NULL,
    prix_litre FLOAT NOT NULL,
    montant_total FLOAT NOT NULL,
    kilometrage INT NOT NULL,
    type_carburant VARCHAR(20) NOT NULL,
    station VARCHAR(100) NULL,
    plein_complet BOOLEAN NOT NULL DEFAULT TRUE,
    consommation_l_100km FLOAT NULL,
    anomalie BOOLEAN NOT NULL DEFAULT FALSE,
    message_anomalie VARCHAR(255) NULL,
    INDEX idx_carburants_vehicule_id (vehicule_id),
    INDEX idx_carburants_date_plein (date_plein)
);

-- Un script d'initialisation peut etre rejoue manuellement. Supprime les
-- doublons historiques puis empeche toute nouvelle repetition exacte.
DELETE c1 FROM carburants c1
JOIN carburants c2
  ON c1.vehicule_id = c2.vehicule_id
 AND c1.date_plein = c2.date_plein
 AND c1.kilometrage = c2.kilometrage
 AND c1.quantite_litres = c2.quantite_litres
 AND c1.prix_litre = c2.prix_litre
WHERE c1.id > c2.id;

SET @index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'carburants'
      AND index_name = 'uq_carburant_plein_unique'
);
SET @sql = IF(
    @index_exists = 0,
    'ALTER TABLE carburants ADD UNIQUE INDEX uq_carburant_plein_unique (vehicule_id, date_plein, kilometrage, quantite_litres, prix_litre)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO carburants (
    vehicule_id,
    chauffeur_id,
    date_plein,
    quantite_litres,
    prix_litre,
    montant_total,
    kilometrage,
    type_carburant,
    station,
    plein_complet,
    consommation_l_100km,
    anomalie,
    message_anomalie
) VALUES
    (1, 1, '2026-01-02', 42.00, 6.50, ROUND(42.00 * 6.50, 2), 44500, 'Diesel', 'Afriquia Casablanca', TRUE, NULL, FALSE, NULL),
    (1, 1, '2026-01-17', 42.00, 6.55, ROUND(42.00 * 6.55, 2), 45100, 'Diesel', 'Shell Rabat', TRUE, 7.00, FALSE, NULL),
    (1, 1, '2026-02-01', 42.00, 6.60, ROUND(42.00 * 6.60, 2), 45700, 'Diesel', 'TotalEnergies Temara', TRUE, 7.00, FALSE, NULL),
    (1, 1, '2026-02-16', 84.00, 6.45, ROUND(84.00 * 6.45, 2), 46300, 'Diesel', 'Afriquia Casablanca', TRUE, 14.00, TRUE, 'Surconsommation: 14 L/100 km pour une moyenne habituelle de 7 L/100 km (seuil 9.1)'),
    (1, 1, '2026-03-03', 42.00, 6.50, ROUND(42.00 * 6.50, 2), 46900, 'Diesel', 'Shell Kenitra', TRUE, 7.00, FALSE, NULL),
    (1, 1, '2026-03-18', 42.00, 6.55, ROUND(42.00 * 6.55, 2), 47500, 'Diesel', 'TotalEnergies Rabat', TRUE, 7.00, FALSE, NULL),

    (2, 2, '2026-01-06', 42.00, 14.25, ROUND(42.00 * 14.25, 2), 61400, 'Essence', 'Winxo Casablanca', TRUE, NULL, FALSE, NULL),
    (2, 2, '2026-01-21', 42.00, 14.30, ROUND(42.00 * 14.30, 2), 62000, 'Essence', 'Afriquia Mohammedia', TRUE, 7.00, FALSE, NULL),
    (2, 2, '2026-02-05', 42.00, 14.35, ROUND(42.00 * 14.35, 2), 62600, 'Essence', 'Shell Rabat', TRUE, 7.00, FALSE, NULL),
    (2, 2, '2026-02-20', 84.00, 14.20, ROUND(84.00 * 14.20, 2), 63200, 'Essence', 'TotalEnergies Casablanca', TRUE, 14.00, TRUE, 'Surconsommation: 14 L/100 km pour une moyenne habituelle de 7 L/100 km (seuil 9.1)'),
    (2, 2, '2026-03-07', 42.00, 14.25, ROUND(42.00 * 14.25, 2), 63800, 'Essence', 'Winxo Mohammedia', TRUE, 7.00, FALSE, NULL),
    (2, 2, '2026-03-22', 42.00, 14.30, ROUND(42.00 * 14.30, 2), 64400, 'Essence', 'Afriquia Casablanca', TRUE, 7.00, FALSE, NULL);
