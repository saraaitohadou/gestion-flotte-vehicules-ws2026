<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entretien_id')->index();
            $table->unsignedBigInteger('vehicule_id')->index();
            $table->string('categorie')->nullable();
            $table->string('type');
            $table->text('message');
            $table->date('echeance_date')->nullable();
            $table->unsignedInteger('echeance_km')->nullable();
            $table->timestamp('resolue_at')->nullable();
            $table->timestamps();

            $table->unique(['entretien_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertes');
    }
};
