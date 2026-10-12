<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Photos attached to a damage assessment.
 *
 * Image metadata (client_uuid, sha256, capture time/location) syncs with the
 * assessment batch; the binary follows separately when bandwidth allows. A row
 * therefore exists in 'pending_upload' before any file is stored, and
 * storage_path is only guaranteed once the binary has been verified against
 * the client-declared sha256.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->foreignUuid('damage_assessment_id')
                ->constrained('damage_assessments')
                ->cascadeOnDelete();
            $table->uuid('client_uuid');
            $table->foreignUuid('sync_batch_id')
                ->nullable()
                ->constrained('sync_batches')
                ->nullOnDelete();

            $table->string('disk', 32);
            $table->string('storage_path', 1024)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->text('caption')->nullable();

            $table->string('sync_status', 32)->default('pending_upload');
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->text('last_sync_error')->nullable();
            $table->timestampTz('uploaded_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'client_uuid']);
            $table->index('damage_assessment_id');
            $table->index(['organization_id', 'sync_status']);
            $table->index('sha256');
            $table->spatialIndex('location');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE assessment_images
                ADD CONSTRAINT assessment_images_sync_status_check
                CHECK (sync_status IN ('pending_upload', 'uploaded', 'processing', 'processed', 'failed'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE assessment_images
                ADD CONSTRAINT assessment_images_stored_file_check
                CHECK (
                    sync_status NOT IN ('uploaded', 'processing', 'processed')
                    OR (storage_path IS NOT NULL AND uploaded_at IS NOT NULL)
                )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE assessment_images
                ADD CONSTRAINT assessment_images_sha256_format_check
                CHECK (sha256 ~ '^[0-9a-f]{64}$')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE assessment_images
                ADD CONSTRAINT assessment_images_numeric_ranges_check
                CHECK (
                    (size_bytes IS NULL OR size_bytes > 0)
                    AND (width IS NULL OR width > 0)
                    AND (height IS NULL OR height > 0)
                    AND sync_attempts >= 0
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_images');
    }
};
