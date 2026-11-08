<?php

use Illuminate\Support\Facades\Schedule;

// Tâche CRON quotidienne : échéances + alertes (exécutée par le conteneur entretien-scheduler)
Schedule::command('entretiens:verifier-echeances')->dailyAt('02:00')->withoutOverlapping();
