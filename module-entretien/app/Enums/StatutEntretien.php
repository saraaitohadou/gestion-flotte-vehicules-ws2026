<?php

namespace App\Enums;

enum StatutEntretien: string
{
    case Planifie = 'planifie';
    case EnCours  = 'en_cours';
    case Termine  = 'termine';
    case Annule   = 'annule';
}
