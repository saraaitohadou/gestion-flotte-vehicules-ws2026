<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entretiens', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('archive_at');
            $table->index('statut');
            $table->index('date_entretien');
        });

        Schema::table('declarations_panne', function (Blueprint $table) {
            $table->string('traite_par')->nullable()->after('entretien_id');
            $table->timestamp('traite_at')->nullable()->after('traite_par');
            $table->text('motif_rejet')->nullable()->after('traite_at');
            $table->index('statut');
            $table->index('entretien_id');
        });

        Schema::create('entretien_historique', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entretien_id')->index();
            $table->string('ancien_statut')->nullable();
            $table->string('nouveau_statut');
            $table->string('action_par')->nullable(); // sub du JWT
            $table->timestamp('created_at')->nullable();

            $table->foreign('entretien_id')->references('id')->on('entretiens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entretien_historique');

        Schema::table('declarations_panne', function (Blueprint $table) {
            $table->dropIndex(['statut']);
            $table->dropIndex(['entretien_id']);
            $table->dropColumn(['traite_par', 'traite_at', 'motif_rejet']);
        });

        Schema::table('entretiens', function (Blueprint $table) {
            $table->dropIndex(['statut']);
            $table->dropIndex(['date_entretien']);
            $table->dropColumn('cancelled_at');
        });
    }
};