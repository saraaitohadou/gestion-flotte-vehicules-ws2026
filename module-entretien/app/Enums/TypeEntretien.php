<?php

namespace App\Enums;

/**
 * Type d'intervention.
 *
 * Ce n'est plus une enum native : la liste des types valides est définie par les
 * clés de config('entretien.types'). Ajouter un type (ex. MAINTENANCE) ne demande
 * donc aucune modification de code, seulement une entrée dans la configuration
 * avec ses catégories et leurs périodicités.
 *
 * Les constantes ci-dessous sont des raccourcis pour les types de base utilisés
 * par le code métier ; elles ne limitent pas les types acceptés.
 */
final class TypeEntretien
{
    public const PREVENTIF = 'PREVENTIF';
    public const CORRECTIF = 'CORRECTIF';

    /** Types autorisés, lus dans la configuration (source de vérité unique). */
    public static function types(): array
    {
        return array_keys((array) config('entretien.types', []));
    }

    public static function estValide(mixed $type): bool
    {
        return is_string($type) && array_key_exists($type, (array) config('entretien.types', []));
    }

    public static function categories(string $type): array
    {
        return array_keys((array) config("entretien.types.{$type}", []));
    }
}
