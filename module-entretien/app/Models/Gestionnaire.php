<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gestionnaire extends Model
{
    protected $table = 'gestionnaires';

    protected $fillable = ['username', 'password_hash', 'actif', 'cree_par'];

    protected $hidden = ['password_hash'];

    protected $casts = ['actif' => 'boolean'];
}