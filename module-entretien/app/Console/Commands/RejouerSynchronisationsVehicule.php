<?php

namespace App\Console\Commands;

use App\Services\SynchronisationVehiculeService;
use Illuminate\Console\Command;

class RejouerSynchronisationsVehicule extends Command
{
    protected $signature   = 'entretiens:synchroniser-vehicules {--limit=50}';
    protected $description = 'Rejoue les synchronisations Véhicule restées en attente après un échec.';

    public function handle(SynchronisationVehiculeService $service): int
    {
        $stats = $service->rejouerEnAttente((int) $this->option('limit'));

        $this->info(
            "{$stats['appliquees']} synchronisation(s) appliquée(s) - "
            . "{$stats['echouees']} encore en échec - "
            . "{$stats['echecs_definitifs']} en échec définitif (dead-letter)."
        );

        return self::SUCCESS;
    }
}
