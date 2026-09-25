<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignUuid('root_quote_id')->nullable()->constrained('quotes')->restrictOnDelete();
            $table->foreignUuid('previous_quote_id')->nullable()->constrained('quotes')->restrictOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->unique(['root_quote_id', 'revision_number']);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropUnique(['root_quote_id', 'revision_number']);
            $table->dropForeign(['root_quote_id']);
            $table->dropForeign(['previous_quote_id']);
            $table->dropColumn(['root_quote_id', 'previous_quote_id', 'revision_number']);
        });
    }
};
