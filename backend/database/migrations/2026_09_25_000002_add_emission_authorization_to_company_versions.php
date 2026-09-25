<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_versions', function (Blueprint $table) {
            $table->boolean('emission_requires_authorization')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('company_versions', function (Blueprint $table) {
            $table->dropColumn('emission_requires_authorization');
        });
    }
};
