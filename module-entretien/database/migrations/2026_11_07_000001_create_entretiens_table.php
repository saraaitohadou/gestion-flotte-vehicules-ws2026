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
            $table->date('date_entretien');
            $table->string('type_entretien');
            $table->text('description');
            $table->decimal('cout', 10, 2)->default(0);
            $table->string('statut')->default('planifie');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entretiens');
    }
};
