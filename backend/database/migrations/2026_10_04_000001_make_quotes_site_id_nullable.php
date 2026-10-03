<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** La sede deja de ser obligatoria al montar una cotización (el cliente sigue siéndolo). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->uuid('site_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('quotes')->whereNull('site_id')->exists()) {
            throw new RuntimeException('No se puede revertir: existen cotizaciones sin sede (site_id NULL). Asigne una sede o conserve la migración; no se borran datos.');
        }
        Schema::table('quotes', function (Blueprint $table): void {
            $table->uuid('site_id')->nullable(false)->change();
        });
    }
};
