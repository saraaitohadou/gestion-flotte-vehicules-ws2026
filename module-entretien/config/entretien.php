<?php

return [

    // Service Véhicule (appels REST inter-services)
    'vehicule_service' => [
        'url'     => env('VEHICULE_SERVICE_URL', 'http://vehicule-service:8001'),
        'timeout' => (int) env('VEHICULE_SERVICE_TIMEOUT', 3),
    ],

    // JWT partagé (HS256)
    'jwt' => [
        'secret' => env('JWT_SECRET'),
    ],

    // Seuils d'alerte (règle : 15 jours ou 500 km avant l'échéance)
    'alertes' => [
        'jours_avant' => (int) env('ALERTE_JOURS_AVANT', 15),
        'km_avant'    => (int) env('ALERTE_KM_AVANT', 500),
    ],

    /*
     | Types d'interventions et périodicités (externalisés : aucune modification du code
     | pour changer "6 mois / 10 000 km" ou ajouter une nouvelle catégorie).
     | Pour un préventif : 'mois' et/ou 'km' => la première condition atteinte déclenche l'alerte.
     */
    'types' => [
        'PREVENTIF' => [
            'VIDANGE'          => ['mois' => 6,  'km' => 10000],
            'PNEUS'            => ['mois' => 24, 'km' => 40000],
            'FREINS'           => ['mois' => 12, 'km' => 30000],
            'VISITE_TECHNIQUE' => ['mois' => 12, 'km' => null],
        ],
        'CORRECTIF' => [
            'PANNE'      => [],
            'REPARATION' => [],
        ],
    ],
];
