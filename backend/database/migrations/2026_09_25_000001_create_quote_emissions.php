<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Emisión oficial de cotizaciones aprobadas (docs/diseno-emision-oficial.md §2).
     * No toca datos existentes. Las únicas columnas mutables son `superseded_*`.
     */
    public function up(): void
    {
        Schema::create('quote_emissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->unique()->constrained('quotes')->restrictOnDelete();
            $table->foreignUuid('root_quote_id')->constrained('quotes')->restrictOnDelete();
            $table->string('quote_number', 20);
            $table->unsignedInteger('revision_number');
            $table->foreignId('approval_review_id')->constrained('quote_reviews')->restrictOnDelete();
            $table->foreignUuid('company_version_id')->constrained('company_versions')->restrictOnDelete();
            $table->boolean('emission_requires_authorization');
            $table->json('issuer');
            $table->json('bank_summary');
            $table->json('clause_version_ids');
            $table->char('snapshot_sha256', 64);
            $table->char('pdf_sha256', 64);
            $table->unsignedInteger('pdf_size');
            $table->string('filename', 80);
            $table->string('template_version', 40);
            $table->string('renderer_version', 40);
            $table->text('reason');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at');
            $table->timestampTz('superseded_at')->nullable();
            $table->uuid('superseded_by')->nullable();
            $table->timestamps();
            $table->index('root_quote_id');
        });

        // La FK autorreferenciada va aparte: en PostgreSQL la clave primaria debe existir antes.
        Schema::table('quote_emissions', function (Blueprint $table) {
            $table->foreign('superseded_by')->references('id')->on('quote_emissions')->restrictOnDelete();
        });

        Schema::create('quote_emission_files', function (Blueprint $table) {
            $table->foreignUuid('emission_id')->primary()->constrained('quote_emissions')->restrictOnDelete();
            // Crypt(base64(pdf)); solo se selecciona al descargar.
            $table->longText('content');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_emission_files');
        Schema::dropIfExists('quote_emissions');
    }
};
