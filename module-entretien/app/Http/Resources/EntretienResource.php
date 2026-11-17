<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Entretien */
class EntretienResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'vehicule_id'    => $this->vehicule_id,
            'type'           => $this->type->value,
            'categorie'      => $this->categorie,
            'description'    => $this->description,
            'date_entretien' => $this->date_entretien?->toDateString(),
            'kilometrage'    => $this->kilometrage,
            'cout'           => (float) $this->cout,
            'statut'         => $this->statut->value,
            'prochaine_echeance' => [
                'date'        => $this->date_prochaine_echeance?->toDateString(),
                'kilometrage' => $this->km_prochaine_echeance,
            ],
            'date_debut'     => $this->date_debut?->toIso8601String(),
            'date_fin'       => $this->date_fin?->toIso8601String(),
            'archive'        => $this->estArchive(),
            'responsables'   => [
                'cree_par'    => $this->created_by,
                'demarre_par' => $this->started_by,
                'termine_par' => $this->finished_by,
                'annule_par'  => $this->cancelled_by,
                'archive_par' => $this->archived_by,
            ],
            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),
        ];
    }
}
