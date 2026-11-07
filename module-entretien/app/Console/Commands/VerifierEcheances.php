<?php

namespace App\Console\Commands;

use App\Services\EcheanceService;
use Illuminate\Console\Command;

class VerifierEcheances extends Command
{
    protected $signature   = 'entretiens:verifier-echeances';
    protected $description = 'Calcule les échéances des entretiens préventifs et génère les alertes (CRON quotidien).';

    public function handle(EcheanceService $service): int
    {
        $s = $service->verifier();
        $this->info("{$s['verifies']} échéance(s) vérifiée(s) - {$s['alertes_proche']} alerte(s) 'proche', {$s['alertes_retard']} alerte(s) 'en retard'.");

        return self::SUCCESS;
    }
}
