<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Alerte */
class AlerteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'entretien_id'  => $this->entretien_id,
            'vehicule_id'   => $this->vehicule_id,
            'categorie'     => $this->categorie,
            'type'          => $this->type,
            'message'       => $this->message,
            'echeance_date' => $this->echeance_date?->toDateString(),
            'echeance_km'   => $this->echeance_km,
            'resolue'       => $this->resolue_at !== null,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
