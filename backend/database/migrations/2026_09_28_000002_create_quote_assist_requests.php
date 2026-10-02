<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una fila por propuesta de la IA (docs/diseno-base-conocimiento-ia.md §5.2).
     * No guarda el texto libre del usuario ni la respuesta del modelo, ni precios.
     */
    public function up(): void
    {
        Schema::create('quote_assist_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('family', 40)->nullable();
            $table->jsonb('knowledge_ids');
            $table->jsonb('proposed_lines');
            $table->unsignedInteger('catalog_items_sent')->default(0);
            $table->unsignedInteger('precedents_sent')->default(0);
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->foreignUuid('root_quote_id')->nullable()->constrained('quotes')->restrictOnDelete();
            $table->unsignedInteger('kept_lines')->nullable();
            $table->unsignedInteger('qty_changed_lines')->nullable();
            $table->unsignedInteger('removed_lines')->nullable();
            $table->unsignedInteger('added_lines')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index('root_quote_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_assist_requests');
    }
};
