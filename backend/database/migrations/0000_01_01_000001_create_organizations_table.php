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
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('slug', 100)->unique();
            $table->string('type', 32);
            $table->string('registration_number', 100)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('is_active');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organizations
                ADD CONSTRAINT organizations_type_check
                CHECK (type IN ('ngo', 'un_agency', 'government', 'community_based', 'private_contractor', 'donor'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE organizations
                ADD CONSTRAINT organizations_slug_format_check
                CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
