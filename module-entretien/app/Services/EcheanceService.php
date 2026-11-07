<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Alerte;
use App\Models\Entretien;
use Carbon\Carbon;

/** Calcul quotidien des échéances et génération des alertes (appelé par le CRON). */
class EcheanceService
{
    public function __construct(private VehiculeClient $vehicules) {}

    /** @return array{verifies:int, alertes_proche:int, alertes_retard:int} */
    public function verifier(): array
    {
        $stats      = ['verifies' => 0, 'alertes_proche' => 0, 'alertes_retard' => 0];
        $aujourdhui = Carbon::today();
        $joursAvant = (int) config('entretien.alertes.jours_avant');
        $kmAvant    = (int) config('entretien.alertes.km_avant');
        $kmCache    = [];

        foreach (Entretien::derniersPreventifsAvecEcheance()->cursor() as $e) {
            $stats['verifies']++;
            $retard = [];
            $proche = [];

            if ($e->date_prochaine_echeance) {
                $jours = (int) $aujourdhui->diffInDays($e->date_prochaine_echeance->copy()->startOfDay(), false);
                if ($jours < 0) {
                    $retard[] = 'échéance du ' . $e->date_prochaine_echeance->toDateString() . ' dépassée de ' . abs($jours) . ' jour(s)';
                } elseif ($jours <= $joursAvant) {
                    $proche[] = "échéance dans {$jours} jour(s) (" . $e->date_prochaine_echeance->toDateString() . ')';
                }
            }

            if ($e->km_prochaine_echeance !== null) {
                $kmActuel = $this->kmActuel($e->vehicule_id, $kmCache);
                if ($kmActuel !== null) {
                    $restant = $e->km_prochaine_echeance - $kmActuel;
                    if ($restant < 0) {
                        $retard[] = "seuil de {$e->km_prochaine_echeance} km dépassé de " . abs($restant) . ' km';
                    } elseif ($restant <= $kmAvant) {
                        $proche[] = "plus que {$restant} km avant le seuil de {$e->km_prochaine_echeance} km";
                    }
                }
            }

            if ($retard) {
                $this->creerAlerte($e, Alerte::EN_RETARD, $retard);
                $stats['alertes_retard']++;
            } elseif ($proche) {
                $this->creerAlerte($e, Alerte::PROCHE, $proche);
                $stats['alertes_proche']++;
            }
        }

        return $stats;
    }

    private function creerAlerte(Entretien $e, string $type, array $raisons): void
    {
        Alerte::updateOrCreate(
            ['entretien_id' => $e->id, 'type' => $type],
            [
                'vehicule_id'   => $e->vehicule_id,
                'categorie'     => $e->categorie,
                'message'       => "Véhicule #{$e->vehicule_id} - {$e->categorie} : " . implode(' ; ', $raisons),
                'echeance_date' => $e->date_prochaine_echeance?->toDateString(),
                'echeance_km'   => $e->km_prochaine_echeance,
                'resolue_at'    => null,
            ],
        );
    }

    /** Kilométrage actuel (null si le service Véhicule est injoignable : on ne bloque pas le CRON). */
    private function kmActuel(int $vehiculeId, array &$cache): ?int
    {
        if (!array_key_exists($vehiculeId, $cache)) {
            try {
                $cache[$vehiculeId] = $this->vehicules->find($vehiculeId)['kilometrage'];
            } catch (BusinessException) {
                $cache[$vehiculeId] = null;
            }
        }

        return $cache[$vehiculeId];
    }
}
