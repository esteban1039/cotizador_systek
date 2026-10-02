<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Base de conocimiento del asistente IA (docs/diseno-base-conocimiento-ia.md §5.1).
     * Dinero solo como precio de referencia interno dentro de `lines` (nunca enviado a Anthropic ni vigente);
     * sin datos de cliente ni texto libre del usuario. Lo específico de
     * PostgreSQL (extensiones, tsvector generado, GIN) se omite en SQLite.
     * `lines_text` (descripciones concatenadas) alimenta el tsvector: una expresión
     * generada no puede recorrer el jsonb de `lines`.
     */
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        if ($pgsql) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        }

        Schema::create('quote_knowledge', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source', 20);
            $table->foreignUuid('source_root_quote_id')->nullable()->constrained('quotes')->restrictOnDelete();
            $table->unsignedSmallInteger('source_revision')->nullable();
            $table->string('source_ref', 120)->nullable();
            $table->string('family', 40);
            $table->text('requirement_text');
            $table->text('scope')->nullable();
            $table->text('exclusions')->nullable();
            $table->jsonb('lines');
            $table->text('lines_text')->nullable();
            $table->string('status', 20);
            $table->decimal('trust', 3, 2);
            $table->boolean('issued')->default(false);
            $table->boolean('ai_assisted')->default(false);
            $table->decimal('human_edit_ratio', 4, 3)->nullable();
            $table->jsonb('scrub_flags');
            $table->timestampTz('captured_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'family']);
            $table->unique(['source', 'source_ref']);
        });

        // Único parcial: una entrada por cotización raíz (SQLite y PostgreSQL admiten índices parciales).
        DB::statement('CREATE UNIQUE INDEX quote_knowledge_source_root_quote_id_unique ON quote_knowledge (source_root_quote_id) WHERE source_root_quote_id IS NOT NULL');

        if ($pgsql) {
            // unaccent() es STABLE; este envoltorio con diccionario explícito es inmutable y permite la columna generada.
            DB::statement("CREATE OR REPLACE FUNCTION quote_knowledge_unaccent(text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE AS \$\$ SELECT public.unaccent('public.unaccent'::regdictionary, \$1) \$\$");
            DB::statement("ALTER TABLE quote_knowledge ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('spanish'::regconfig, quote_knowledge_unaccent(requirement_text || ' ' || coalesce(lines_text, '')))) STORED");
            DB::statement('CREATE INDEX quote_knowledge_search_vector_gin ON quote_knowledge USING GIN (search_vector)');
            DB::statement('CREATE INDEX quote_knowledge_requirement_text_trgm ON quote_knowledge USING GIN (requirement_text gin_trgm_ops)');
        }
    }

    /**
     * Elimina solo lo creado aquí; las extensiones pueden estar en uso por otros objetos y se conservan.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_knowledge');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS quote_knowledge_unaccent(text)');
        }
    }
};
