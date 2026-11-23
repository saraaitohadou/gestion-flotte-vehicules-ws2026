<?php

namespace App\Services;

use App\Enums\StatutEntretien;
use App\Models\Entretien;
use Carbon\Carbon;
use DateTimeInterface;
use DomainException;
use InvalidArgumentException;

class EntretienService
{
    /** Somme des couts (liste de nombres). Refuse les couts negatifs. */
    public function calculerCoutTotal(array $couts): float
    {
        $total = 0.0;

        foreach ($couts as $cout) {
            if ($cout < 0) {
                throw new InvalidArgumentException('Le cout ne peut pas etre negatif.');
            }
            $total += $cout;
        }

        return round($total, 2);
    }

    /** En retard = la date de l'entretien est strictement avant aujourd'hui. */
    public function estEnRetard(DateTimeInterface $dateEntretien, DateTimeInterface $aujourdhui): bool
    {
        return $dateEntretien->format('Y-m-d') < $aujourdhui->format('Y-m-d');
    }

    /** Planifie -> EnCours | Annule ; EnCours -> Termine | Annule ; aucun retour en arriere. */
    public function peutChangerStatut(StatutEntretien $de, StatutEntretien $vers): bool
    {
        $autorises = match ($de) {
            StatutEntretien::Planifie => [StatutEntretien::EnCours, StatutEntretien::Annule],
            StatutEntretien::EnCours  => [StatutEntretien::Termine, StatutEntretien::Annule],
            default                   => [],
        };

        return in_array($vers, $autorises, true);
    }

    /** Retourne le nouveau statut, ou refuse la transition. */
    public function changerStatut(StatutEntretien $de, StatutEntretien $vers): StatutEntretien
    {
        if (! $this->peutChangerStatut($de, $vers)) {
            throw new DomainException("Transition interdite : {$de->value} vers {$vers->value}.");
        }

        return $vers;
    }

    /**
     * Échéance selon le type ET la catégorie (config/entretien.php).
     *
     * La règle est lue sur le type de l'entretien lui-même : un type ajouté à la
     * configuration bénéficie automatiquement du calcul, sans modification de code.
     * Un type/catégorie sans périodicité (correctif, par exemple) n'a pas d'échéance.
     *
     * @return array{0: ?Carbon, 1: ?int}
     */
    public function calculerEcheance(Entretien $entretien, Carbon $dateReference, ?int $kilometrage): array
    {
        $regle = config('entretien.types.' . $entretien->type . '.' . $entretien->categorie, []);

        if (! is_array($regle) || (! isset($regle['mois']) && ! isset($regle['km']))) {
            return [null, null];
        }

        $date  = isset($regle['mois']) ? $dateReference->copy()->addMonths((int) $regle['mois']) : null;
        $kmEch = (isset($regle['km']) && $kilometrage !== null) ? $kilometrage + (int) $regle['km'] : null;

        return [$date, $kmEch];
    }
}


