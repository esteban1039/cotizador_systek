<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mfa_enabled')->default(false);
            $table->text('mfa_secret')->nullable();
            $table->text('mfa_pending_secret')->nullable();
            $table->timestamp('mfa_pending_expires_at')->nullable();
            $table->text('mfa_recovery_codes')->nullable();
            $table->bigInteger('mfa_last_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['mfa_enabled', 'mfa_secret', 'mfa_pending_secret', 'mfa_pending_expires_at', 'mfa_recovery_codes', 'mfa_last_step']));
    }
};
