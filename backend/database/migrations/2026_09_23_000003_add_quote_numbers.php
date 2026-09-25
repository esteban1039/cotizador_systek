<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backfill reversible: asigna `COT-{año}-{n:04d}` a las cotizaciones raíz
     * existentes (ordenadas por fecha de creación) y copia el número a sus
     * revisiones. No modifica `snapshot`. El año se toma de `created_at` en
     * hora de Bogotá (`config('app.timezone')`).
     */
    public function up(): void
    {
        Schema::create('quote_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->string('quote_number', 20)->nullable()->after('id');
        });

        $this->backfillQuoteNumbers();

        Schema::table('quotes', function (Blueprint $table) {
            $table->unique(['quote_number', 'revision_number']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Reaplicar `up()` tras un `down()` reasigna los mismos números mientras
     * no se hayan creado cotizaciones nuevas entre medias (el orden por
     * `created_at, id` es determinista, pero un alta intermedia desplaza la
     * numeración de las cotizaciones posteriores a ella).
     */
    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropUnique(['quote_number', 'revision_number']);
            $table->dropColumn('quote_number');
        });

        Schema::dropIfExists('quote_number_sequences');
    }

    private function backfillQuoteNumbers(): void
    {
        $timezone = config('app.timezone', 'America/Bogota');

        /** @var array<int, int> $lastByYear */
        $lastByYear = [];

        DB::table('quotes')
            ->whereNull('root_quote_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->select(['id', 'created_at'])
            ->cursor()
            ->each(function (object $root) use (&$lastByYear, $timezone): void {
                $year = (int) Carbon::parse($root->created_at, $timezone)->format('Y');
                $lastByYear[$year] = ($lastByYear[$year] ?? 0) + 1;
                $quoteNumber = sprintf('COT-%04d-%04d', $year, $lastByYear[$year]);

                DB::table('quotes')->where('id', $root->id)->update(['quote_number' => $quoteNumber]);
                DB::table('quotes')->where('root_quote_id', $root->id)->update(['quote_number' => $quoteNumber]);
            });

        foreach ($lastByYear as $year => $lastNumber) {
            DB::table('quote_number_sequences')->updateOrInsert(
                ['year' => $year],
                ['last_number' => $lastNumber, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
};
