<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log of every offline batch pushed by a device.
 *
 * (organization_id, idempotency_key) is the durable idempotency guarantee: a
 * retried batch resolves to the existing row instead of re-ingesting records.
 * payload_hash detects a client reusing a key for a different payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('payload_hash', 64);
            $table->string('device_id', 128);
            $table->string('device_platform', 32)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('status', 32)->default('received');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->jsonb('result_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('client_sent_at')->nullable();
            $table->integer('clock_skew_seconds')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'received_at']);
            $table->index(['device_id', 'received_at']);
            $table->index(['user_id', 'received_at']);
            $table->index('status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sync_batches
                ADD CONSTRAINT sync_batches_status_check
                CHECK (status IN ('received', 'processing', 'completed', 'partially_failed', 'failed'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE sync_batches
                ADD CONSTRAINT sync_batches_counts_check
                CHECK (
                    total_items >= 0
                    AND created_count >= 0
                    AND updated_count >= 0
                    AND skipped_count >= 0
                    AND failed_count >= 0
                    AND created_count + updated_count + skipped_count + failed_count <= total_items
                )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE sync_batches
                ADD CONSTRAINT sync_batches_payload_hash_format_check
                CHECK (payload_hash ~ '^[0-9a-f]{64}$')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_batches');
    }
};
