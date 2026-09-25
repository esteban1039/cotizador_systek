<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No inserta datos: la fila `companies` (code = 'issuer') se crea bajo demanda
     * con insertOrIgnore desde el repositorio, y la primera versión la publica
     * el seeder idempotente `InitialConfigurationSeeder` (fuera del alcance de dba).
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->timestamps();
        });

        Schema::create('company_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('historical');
            $table->string('legal_name', 200);
            $table->string('trade_name', 100)->nullable();
            $table->string('nit', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('signer_name', 150)->nullable();
            $table->string('signer_title', 150)->nullable();
            // JSON cifrado (cast encrypted:array en App\Models\CompanyVersion):
            // {bank_name, account_type: savings|checking, account_number, account_holder|null}
            $table->text('bank_account')->nullable();
            $table->string('origin', 20)->default('admin');
            $table->text('reason');
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'version']);
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_versions');
        Schema::dropIfExists('companies');
    }
};
