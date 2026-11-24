<?php

return [
    /** Source de vérité unique pour l'URL du service Carburant (lecture seule). */
    'url'     => rtrim((string) env('CARBURANT_SERVICE_URL', 'http://carburant-service:8000'), '/'),

    /** Les routes Carburant sont /carburants/... : aucun prefixe par defaut. */
    'prefix'  => trim((string) env('CARBURANT_API_PREFIX', ''), '/'),

    'api_key' => (string) env('SERVICE_API_KEY', ''),

    'timeout' => (int) env('CARBURANT_SERVICE_TIMEOUT', 3),
];
