<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeclarationPanne extends Model
{
    public const DECLAREE = 'DECLAREE';
    public const TRAITEE  = 'TRAITEE';
    public const REJETEE  = 'REJETEE';

    protected $table = 'declarations_panne';

    protected $fillable = [
        'vehicule_id', 'chauffeur_id', 'categorie', 'description', 'kilometrage',
        'statut', 'chauffeur_verifie', 'entretien_id', 'created_by',
    ];

    protected $attributes = [
        'statut'            => 'DECLAREE',
        'chauffeur_verifie' => true,
    ];

    protected function casts(): array
    {
        return ['chauffeur_verifie' => 'boolean'];
    }
}