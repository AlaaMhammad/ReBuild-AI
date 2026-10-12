<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum personal access tokens, adapted for UUID user keys and bound to the
 * client that requested them (field device vs. web dashboard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->string('client_type', 32);
            $table->string('device_id', 128)->nullable();
            $table->ipAddress('created_ip')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();

            $table->index(['tokenable_type', 'tokenable_id', 'device_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE personal_access_tokens
                ADD CONSTRAINT personal_access_tokens_client_type_check
                CHECK (client_type IN ('field_device', 'web_dashboard'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE personal_access_tokens
                ADD CONSTRAINT personal_access_tokens_field_device_id_check
                CHECK (client_type <> 'field_device' OR device_id IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
