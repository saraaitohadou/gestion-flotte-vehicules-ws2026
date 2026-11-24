<?php

return [
    /** Source de vérité UNIQUE pour l'URL du service Véhicule (utilisée par VehiculeClient). */
    'url'      => rtrim((string) env('VEHICULE_SERVICE_URL', 'http://localhost:8001'), '/'),

    /** Préfixe des routes inter-services. Vide si le service Véhicule expose /vehicules directement. */
    'prefix'   => trim((string) env('VEHICULE_API_PREFIX', '/api'), '/'),

    'api_key'  => (string) env('SERVICE_API_KEY', ''),

    /** Timeout des appels HTTP (config centralisée, plus de doublon dans entretien.php). */
    'timeout'  => (int) env('VEHICULE_SERVICE_TIMEOUT', 3),
];
