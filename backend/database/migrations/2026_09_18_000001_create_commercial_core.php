<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('nit')->nullable()->unique();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('city');
            $table->string('address')->nullable();
            $table->timestamps();
        });
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku')->unique();
            $table->string('description');
            $table->string('family');
            $table->string('unit');
            $table->boolean('active')->default(false);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
        Schema::create('price_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('catalog_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('cost_cents');
            $table->unsignedSmallInteger('tax_bps');
            $table->date('valid_from');
            $table->date('valid_until');
            $table->string('status')->default('historical');
            $table->timestamps();
            $table->unique(['catalog_item_id', 'version']);
        });
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('site_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->json('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['quotes', 'price_versions', 'catalog_items', 'sites', 'clients'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
