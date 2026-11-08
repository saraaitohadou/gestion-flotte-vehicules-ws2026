<?php

namespace App\Models;

use App\Enums\StatutEntretien;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Entretien extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicule_id', 'date_entretien', 'type_entretien',
        'description', 'cout', 'statut',
    ];

    // Memes valeurs par defaut que la migration
    protected $attributes = [
        'statut' => 'planifie',
        'cout'   => 0,
    ];

    protected function casts(): array
    {
        return [
            'date_entretien' => 'date:Y-m-d',
            'cout' => 'decimal:2',
            'statut' => StatutEntretien::class,
        ];
    }
}
