<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DeclarationPanne */
class DeclarationPanneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'vehicule_id'       => $this->vehicule_id,
            'chauffeur_id'      => $this->chauffeur_id,
            'categorie'         => $this->categorie,
            'description'       => $this->description,
            'kilometrage'       => $this->kilometrage,
            'statut'            => $this->statut,
            'chauffeur_verifie' => (bool) $this->chauffeur_verifie,
            'entretien_id'      => $this->entretien_id,
            'traite_par'        => $this->traite_par,
            'traite_at'         => $this->traite_at?->toIso8601String(),
            'motif_rejet'       => $this->motif_rejet,
            'created_by'        => $this->created_by,
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}