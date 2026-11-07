<?php

namespace App\Console\Commands;

use App\Support\AuthUser;
use App\Support\JwtService;
use Illuminate\Console\Command;

class GenererToken extends Command
{
    protected $signature   = 'entretien:token {role : administrateur|gestionnaire|chauffeur} {--vehicule= : id du véhicule affecté (chauffeur)} {--ttl=1440 : durée en minutes}';
    protected $description = 'Génère un JWT de développement pour tester l\'API avec Postman.';

    public function handle(JwtService $jwt): int
    {
        $role = AuthUser::normaliserRole($this->argument('role'));
        $claims = ['sub' => 'dev-' . strtolower($role), 'role' => $role];
        if ($this->option('vehicule')) {
            $claims['vehicule_id'] = (int) $this->option('vehicule');
        }

        $this->line($jwt->issue($claims, (int) $this->option('ttl') * 60));

        return self::SUCCESS;
    }
}
