<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boîte d'envoi des synchronisations avec le service Véhicule.
 *
 * Une ligne est écrite DANS la même transaction que la modification de l'entretien :
 * la base locale reste la source de vérité et aucune mutation du véhicule
 * ne peut partir avant que la transaction locale soit validée (commit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations_outbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entretien_id')->index();
            $table->unsignedBigInteger('vehicule_id');

            // Nature de la mutation envoyée au service Véhicule
            $table->string('action');

            // [{"champ":"statut","valeur":"EN_MAINTENANCE"}, ...]
            $table->json('operations');
            $table->json('operations_inverses');

            // Snapshots bruts de l'entretien (avant / après) pour la compensation
            $table->json('entretien_avant');
            $table->json('entretien_apres');

            // Clé stable : un même PATCH ne peut pas être appliqué deux fois
            $table->string('idempotency_key')->unique();

            // EN_ATTENTE | APPLIQUE | COMPENSE
            $table->string('statut')->default('EN_ATTENTE');
            $table->unsignedInteger('tentatives')->default(0);
            $table->text('dernier_erreur')->nullable();
            $table->timestamp('applique_at')->nullable();

            $table->timestamps();
            $table->index(['statut', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations_outbox');
    }
};
