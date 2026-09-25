<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('quoter');
            $table->boolean('active')->default(true);
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestamps();
        });
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('subject_id');
            $table->json('details');
            $table->timestamp('created_at');
        });
        Schema::create('quote_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('quote_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('decision');
            $table->text('reason');
            $table->json('validation');
            $table->timestamp('created_at');
        });
        Schema::create('commercial_rules', function (Blueprint $table) {
            $table->string('family')->primary();
            $table->unsignedSmallInteger('minimum_margin_bps');
            $table->unsignedSmallInteger('max_discount_bps');
            $table->unsignedBigInteger('review_above_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_rules');
        Schema::dropIfExists('quote_reviews');
        Schema::dropIfExists('audit_logs');
        Schema::table('quotes', fn (Blueprint $table) => $table->dropConstrainedForeignId('created_by'));
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('personal_access_tokens');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'active']));
    }
};
