<?php
namespace App\Models;

enum StatutVehicule: string
{
    case DISPONIBLE = 'DISPONIBLE';
    case EN_MISSION = 'EN_MISSION';
    case EN_MAINTENANCE = 'EN_MAINTENANCE';
    case HORS_SERVICE = 'HORS_SERVICE';
}