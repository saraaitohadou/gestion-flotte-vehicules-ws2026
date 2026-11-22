<?php

return [
    'url'     => env('CHAUFFEUR_SERVICE_URL', 'http://chauffeur-mock:8003'),
    'prefix'  => env('CHAUFFEUR_API_PREFIX', '/api'),
    'api_key' => env('SERVICE_API_KEY', ''),
    'timeout' => (int) env('CHAUFFEUR_SERVICE_TIMEOUT', 3),
];