<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Garantie en base qu'un véhicule ne peut avoir qu'UN SEUL entretien EN_COURS.
 *
 * MySQL 8 ne sait pas créer d'index partiel (WHERE statut = 'EN_COURS').
 * On utilise donc une colonne générée qui vaut vehicule_id uniquement pour
 * les entretiens EN_COURS, et NULL sinon (NULL multiples autorisés par UNIQUE).
 *
 * SQLite < 3.31 ne sait pas maintenir une colonne générée insérée en masse :
 * on y utilise une colonne ordinaire renseignée par un trigger et un UPDATE initial.
 */
return new class extends Migration
{
    private function estSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    public function up(): void
    {
        if ($this->estSqlite()) {
            $this->upSqlite();
            return;
        }

        Schema::table('entretiens', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicule_en_cours')
                ->nullable()
                ->generatedAs("CASE WHEN statut = 'EN_COURS' THEN vehicule_id END")
                ->stored();

            $table->unique('vehicule_en_cours', 'entretiens_en_cours_par_vehicule_unique');
        });
    }

    private function upSqlite(): void
    {
        Schema::table('entretiens', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicule_en_cours')->nullable();
            $table->unique('vehicule_en_cours', 'entretiens_en_cours_par_vehicule_unique');
        });

        // Synchronise la colonne sur les lignes existantes, puis à chaque écriture.
        DB::statement("UPDATE entretiens SET vehicule_en_cours = CASE WHEN statut = 'EN_COURS' THEN vehicule_id END");
        DB::statement(<<<'SQL'
CREATE TRIGGER entretiens_vehicule_en_cours_insert AFTER INSERT ON entretiens
BEGIN
    UPDATE entretiens SET vehicule_en_cours =
        CASE WHEN NEW.statut = 'EN_COURS' THEN NEW.vehicule_id END
    WHERE id = NEW.id;
END
SQL);
        DB::statement(<<<'SQL'
CREATE TRIGGER entretiens_vehicule_en_cours_update AFTER UPDATE OF statut, vehicule_id ON entretiens
BEGIN
    UPDATE entretiens SET vehicule_en_cours =
        CASE WHEN NEW.statut = 'EN_COURS' THEN NEW.vehicule_id END
    WHERE id = NEW.id;
END
SQL);
    }

    public function down(): void
    {
        if ($this->estSqlite()) {
            DB::statement('DROP TRIGGER IF EXISTS entretiens_vehicule_en_cours_insert');
            DB::statement('DROP TRIGGER IF EXISTS entretiens_vehicule_en_cours_update');
        }

        Schema::table('entretiens', function (Blueprint $table) {
            $table->dropUnique('entretiens_en_cours_par_vehicule_unique');
            $table->dropColumn('vehicule_en_cours');
        });
    }
};
