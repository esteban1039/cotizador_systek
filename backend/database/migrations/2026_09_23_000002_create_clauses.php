<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No inserta datos: el contenido inicial de las 56 cláusulas lo carga
     * `InitialConfigurationSeeder` (fuera del alcance de dba).
     */
    public function up(): void
    {
        Schema::create('clauses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('family', 40);
            $table->string('type', 30);
            $table->string('title', 120);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['family', 'type', 'title']);
            $table->index(['family', 'active']);
        });

        // Red de seguridad además del bloqueo aplicativo por (family, type) en el
        // repositorio: sintaxis de índice único parcial válida en PostgreSQL y SQLite.
        DB::statement('CREATE UNIQUE INDEX clauses_one_default_per_family_type ON clauses (family, type) WHERE is_default');

        Schema::create('clause_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('clause_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('historical');
            $table->text('body');
            $table->char('body_hash', 64);
            $table->string('origin', 20)->default('admin');
            $table->text('reason');
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['clause_id', 'version']);
            $table->index(['clause_id', 'status']);
            $table->index('body_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clause_versions');
        // El índice parcial se elimina junto con la tabla.
        Schema::dropIfExists('clauses');
    }
};
