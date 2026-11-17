<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Alerte extends Model
{
    public const PROCHE = 'PROCHE';
    public const EN_RETARD = 'EN_RETARD';

    protected $fillable = [
        'entretien_id', 'vehicule_id', 'categorie', 'type', 'message',
        'echeance_date', 'echeance_km', 'resolue_at',
    ];

    protected function casts(): array
    {
        return [
            'echeance_date' => 'date',
            'resolue_at' => 'datetime',
        ];
    }
}