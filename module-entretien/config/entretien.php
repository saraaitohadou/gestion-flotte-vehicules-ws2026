<?php

return [

    // Les réglages du service Véhicule (URL, préfixe, clé API, timeout)
    // sont centralisés dans config/vehicule.php.

    // Saga Véhicule : au-delà de ce nombre de tentatives, la ligne passe en
    // ECHEC_DEFINITIF (dead-letter) au lieu de rester bloquée EN_ATTENTE.
    'synchronisation' => [
        'tentatives_max' => max(1, (int) env('SYNCHRONISATION_TENTATIVES_MAX', 3)),
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
