<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_id')->unique();
            $table->string('content_hash', 64)->unique();
            $table->string('title');
            $table->text('source_url')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_nit', 40)->nullable();
            $table->string('family', 40)->nullable();
            $table->date('issued_on')->nullable();
            $table->text('source_text');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignUuid('linked_client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->text('review_reason')->nullable();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('historical_document_sources', function (Blueprint $table) {
            $table->string('source_id')->primary();
            $table->foreignUuid('document_id')->constrained('historical_documents')->restrictOnDelete();
            $table->string('title');
            $table->text('source_url')->nullable();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_document_sources');
        Schema::dropIfExists('historical_documents');
    }
};
