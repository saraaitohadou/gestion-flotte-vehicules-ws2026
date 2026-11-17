<?php

namespace App\Enums;

enum StatutEntretien: string
{
    case Planifie = 'PLANIFIE';
    case EnCours  = 'EN_COURS';
    case Termine  = 'TERMINE';
    case Annule   = 'ANNULE';
}
