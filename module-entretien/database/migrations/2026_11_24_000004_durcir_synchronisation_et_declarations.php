<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durcissement de la saga Véhicule et de la conversion Panne -> Entretien.
 *
 * - Chaque opération de la saga garde sa propre trace d'application : un PATCH
 *   déjà effectué n'est jamais renvoyé lors d'une nouvelle tentative.
 * - Une limite de tentatives et un statut terminal explicite évitent qu'une
 *   ligne reste indéfiniment EN_ATTENTE.
 * - Une déclaration de panne ne peut produire qu'un seul entretien, garantie
 *   également par l'index unique (en plus du verrou de ligne applicatif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entretiens', function (Blueprint $table) {
            $table->unsignedBigInteger('declaration_panne_id')->nullable();
            $table->unique('declaration_panne_id', 'entretiens_declaration_panne_unique');
        });

        Schema::table('integrations_outbox', function (Blueprint $table) {
            $table->json('etat_vehicule_avant')->nullable();
            $table->json('operations_appliquees')->nullable();
            $table->unsignedInteger('tentatives_max')->default(3);
            $table->timestamp('derniere_tentative_at')->nullable();
            $table->text('compensation_erreur')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integrations_outbox', function (Blueprint $table) {
            $table->dropColumn([
                'etat_vehicule_avant', 'operations_appliquees',
                'tentatives_max', 'derniere_tentative_at', 'compensation_erreur',
            ]);
        });

        Schema::table('entretiens', function (Blueprint $table) {
            $table->dropUnique('entretiens_declaration_panne_unique');
            $table->dropColumn('declaration_panne_id');
        });
    }
};
