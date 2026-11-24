<?php

use Illuminate\Support\Facades\Schedule;

// Reprise automatique des synchronisations Véhicule différées (compensations, PATCH perdus)
Schedule::command('entretiens:synchroniser-vehicules')->everyMinute()->withoutOverlapping();
