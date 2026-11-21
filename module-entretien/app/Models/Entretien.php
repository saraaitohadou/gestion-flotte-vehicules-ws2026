<?php

namespace App\Models;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Entretien extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicule_id', 'type', 'categorie', 'description', 'date_entretien',
        'kilometrage', 'cout', 'statut', 'date_debut', 'date_fin',
        'date_prochaine_echeance', 'km_prochaine_echeance', 'archive_at',
        'created_by', 'started_by', 'finished_by', 'cancelled_by', 'archived_by', 'cancelled_at',
    ];

    // Memes valeurs par defaut que la migration
    protected $attributes = [
        'statut' => 'PLANIFIE',
        'cout'   => 0,
    ];

    protected function casts(): array
    {
        return [
            'type'                    => TypeEntretien::class,
            'statut'                  => StatutEntretien::class,
            'date_entretien'          => 'date:Y-m-d',
            'date_debut'              => 'datetime',
            'date_fin'                => 'datetime',
            'date_prochaine_echeance' => 'date',
            'archive_at'              => 'datetime',
            'cancelled_at'            => 'datetime',
            'cout'                    => 'decimal:2',
        ];
    }

    public function historique()
    {
        return $this->hasMany(EntretienHistorique::class)->orderBy('id');
    }

    public function estArchive(): bool
    {
        return $this->archive_at !== null;
    }

    /** Dernier entretien termine par (vehicule, categorie) qui a une echeance a surveiller. */
    public function scopeDerniersPreventifsAvecEcheance(Builder $query): Builder
    {
        return $query
            ->whereIn('id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('entretiens')
                    ->where('statut', StatutEntretien::Termine->value)
                    ->whereNotNull('categorie')
                    ->groupBy('vehicule_id', 'categorie');
            })
            ->where(function ($w) {
                $w->whereNotNull('date_prochaine_echeance')
                  ->orWhereNotNull('km_prochaine_echeance');
            });
    }
}
