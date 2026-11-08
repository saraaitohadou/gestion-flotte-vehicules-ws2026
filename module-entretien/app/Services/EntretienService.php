<?php

namespace App\Services;

use App\Enums\StatutEntretien;
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
}


