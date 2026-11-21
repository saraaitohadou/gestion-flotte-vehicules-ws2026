<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntretienHistorique extends Model
{
    protected $table = 'entretien_historique';
    public const UPDATED_AT = null;

    protected $fillable = ['entretien_id', 'ancien_statut', 'nouveau_statut', 'action_par'];
}