<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PDF oficiales en S3: la fila guarda la clave del objeto y `content` queda vacío.
     * Los PDF ya archivados en la base no se tocan (siguen en `content`).
     */
    public function up(): void
    {
        Schema::table('quote_emission_files', function (Blueprint $table) {
            $table->string('object_key', 120)->nullable()->unique();
            $table->longText('content')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Revertir dejaría emisiones sin archivo: hay que traer antes esos PDF desde S3 (no se pierde nada).
        if (DB::table('quote_emission_files')->whereNull('content')->exists()) {
            throw new RuntimeException('Hay PDF oficiales que solo existen en S3; no se puede revertir sin copiarlos a la base.');
        }
        Schema::table('quote_emission_files', function (Blueprint $table) {
            $table->dropUnique(['object_key']);
            $table->dropColumn('object_key');
            $table->longText('content')->nullable(false)->change();
        });
    }
};
