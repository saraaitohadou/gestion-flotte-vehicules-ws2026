<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('declarations_panne', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicule_id')->index();
            $table->unsignedBigInteger('chauffeur_id')->index();
            $table->string('categorie');
            $table->text('description');
            $table->unsignedInteger('kilometrage')->nullable();
            $table->string('statut')->default('DECLAREE');
            $table->boolean('chauffeur_verifie')->default(true);
            $table->unsignedBigInteger('entretien_id')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('declarations_panne');
    }
};