<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationOutbox extends Model
{
    public const ACTION_MAJ = 'METTRE_A_JOUR';

    public const EN_ATTENTE          = 'EN_ATTENTE';
    public const APPLIQUE            = 'APPLIQUE';
    public const COMPENSE            = 'COMPENSE';
    public const ECHEC_DEFINITIF     = 'ECHEC_DEFINITIF';
    public const COMPENSATION_IGNOREE = 'COMPENSATION_IGNOREE';

    protected $table = 'integrations_outbox';

    protected $fillable = [
        'entretien_id', 'vehicule_id', 'action',
        'operations', 'operations_inverses', 'etat_vehicule_avant', 'operations_appliquees',
        'entretien_avant', 'entretien_apres',
        'idempotency_key', 'statut', 'tentatives', 'tentatives_max',
        'dernier_erreur', 'derniere_tentative_at', 'compensation_erreur', 'applique_at',
    ];

    protected function casts(): array
    {
        return [
            'operations'            => 'array',
            'operations_inverses'   => 'array',
            'etat_vehicule_avant'   => 'array',
            'operations_appliquees' => 'array',
            'entretien_avant'       => 'array',
            'entretien_apres'       => 'array',
            'applique_at'           => 'datetime',
            'derniere_tentative_at' => 'datetime',
        ];
    }

    public function entretien(): BelongsTo
    {
        return $this->belongsTo(Entretien::class);
    }

    /** Nombre de tentatives autorisé (valeur par défaut incluse pour les lignes historiques). */
    public function tentativesMax(): int
    {
        return max(1, (int) ($this->tentatives_max ?: config('entretien.synchronisation.tentatives_max', 3)));
    }
}
