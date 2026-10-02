<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo entre una línea libre de la instantánea (inmutable) y el ítem de catálogo
     * creado al aprobar. Solo inserciones; no toca catalog_items ni price_versions.
     */
    public function up(): void
    {
        Schema::create('quote_free_line_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->constrained('quotes')->restrictOnDelete();
            $table->uuid('free_line_id');
            $table->foreignUuid('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->foreignUuid('price_version_id')->constrained('price_versions')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->nullable();
            $table->unique(['quote_id', 'free_line_id']);
            $table->unique('catalog_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_free_line_items');
    }
};
