<?php

return [
    'check' => filter_var(env('VEHICULE_CHECK', false), FILTER_VALIDATE_BOOLEAN),
    'url'   => env('VEHICULE_SERVICE_URL', 'http://localhost:8002'),
];
