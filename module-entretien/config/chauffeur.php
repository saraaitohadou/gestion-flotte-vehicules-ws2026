<?php

return [
    'url'     => env('CHAUFFEUR_SERVICE_URL', 'http://chauffeur-mock:8003'),
    'prefix'  => env('CHAUFFEUR_API_PREFIX', '/api'),
    'timeout' => (int) env('CHAUFFEUR_SERVICE_TIMEOUT', 3),
];