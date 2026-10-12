<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->string('name', 255);
            $table->string('email', 255)->unique();
            $table->string('phone', 32)->nullable();
            $table->string('role', 32);
            $table->string('locale', 8)->default('ar');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'role']);
            $table->index(['organization_id', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT users_role_check
                CHECK (role IN ('admin', 'coordinator', 'field_agent', 'contractor', 'donor'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT users_email_lowercase_check
                CHECK (email = lower(email))
            SQL);

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
