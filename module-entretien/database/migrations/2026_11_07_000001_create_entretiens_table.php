<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entretiens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicule_id')->index();
            $table->string('type');
            $table->string('categorie');
            $table->text('description');
            $table->date('date_entretien');
            $table->unsignedInteger('kilometrage')->nullable();
            $table->decimal('cout', 10, 2)->default(0);
            $table->string('statut')->default('PLANIFIE');

            $table->dateTime('date_debut')->nullable();
            $table->dateTime('date_fin')->nullable();

            $table->date('date_prochaine_echeance')->nullable();
            $table->unsignedInteger('km_prochaine_echeance')->nullable();

            $table->timestamp('archive_at')->nullable();

            // Utilisateur responsable de chaque action (sub du JWT)
            $table->string('created_by')->nullable();
            $table->string('started_by')->nullable();
            $table->string('finished_by')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->string('archived_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entretiens');
    }
};
