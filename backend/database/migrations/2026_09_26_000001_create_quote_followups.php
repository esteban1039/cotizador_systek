<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seguimiento comercial de cotizaciones emitidas. Solo inserciones (append-only);
     * no cambia `quotes.status` ni toca datos existentes.
     */
    public function up(): void
    {
        Schema::create('quote_followups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->constrained('quotes')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('channel', 20)->nullable();
            $table->timestampTz('occurred_at');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['quote_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_followups');
    }
};
