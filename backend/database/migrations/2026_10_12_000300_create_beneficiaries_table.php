<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Households registered for relief and reconstruction.
 *
 * National IDs are stored encrypted (application-level AES-256) and indexed via
 * a keyed HMAC-SHA256 digest, so the database can enforce uniqueness and support
 * cross-organization deduplication without ever holding the plaintext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->foreignUuid('sync_batch_id')
                ->nullable()
                ->constrained('sync_batches')
                ->nullOnDelete();
            $table->foreignUuid('registered_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('national_id')->nullable();
            $table->char('national_id_hash', 64)->nullable();
            $table->string('full_name', 255);
            $table->string('full_name_normalized', 255);
            $table->string('gender', 16)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 32)->nullable();

            $table->string('governorate', 32)->nullable();
            $table->string('displacement_status', 32)->nullable();
            $table->text('address_description')->nullable();

            $table->unsignedSmallInteger('household_size')->default(1);
            $table->unsignedSmallInteger('children_count')->default(0);
            $table->unsignedSmallInteger('elderly_count')->default(0);
            $table->unsignedSmallInteger('disabled_count')->default(0);
            $table->unsignedSmallInteger('pregnant_lactating_count')->default(0);
            $table->boolean('is_female_headed')->default(false);
            $table->jsonb('vulnerability_tags')->default(DB::raw("'[]'::jsonb"));
            $table->text('notes')->nullable();

            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('client_created_at')->nullable();
            $table->timestampTz('client_updated_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'client_uuid']);
            $table->index('national_id_hash');
            $table->index(['organization_id', 'governorate']);
            $table->index(['organization_id', 'created_at']);
            $table->index('vulnerability_tags', null, 'gin');
        });

        // One active registration per person per organization. Cross-organization
        // duplicates are expected and are resolved by the deduplication service.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX beneficiaries_org_national_id_hash_active_unique
                ON beneficiaries (organization_id, national_id_hash)
                WHERE national_id_hash IS NOT NULL AND deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX beneficiaries_full_name_normalized_trgm_index
                ON beneficiaries USING gin (full_name_normalized gin_trgm_ops)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE beneficiaries
                ADD CONSTRAINT beneficiaries_gender_check
                CHECK (gender IS NULL OR gender IN ('male', 'female'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE beneficiaries
                ADD CONSTRAINT beneficiaries_governorate_check
                CHECK (governorate IS NULL OR governorate IN ('north_gaza', 'gaza', 'deir_al_balah', 'khan_yunis', 'rafah'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE beneficiaries
                ADD CONSTRAINT beneficiaries_displacement_status_check
                CHECK (displacement_status IS NULL OR displacement_status IN (
                    'resident', 'displaced_shelter', 'displaced_host_family', 'displaced_tent', 'returnee'
                ))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE beneficiaries
                ADD CONSTRAINT beneficiaries_household_metrics_check
                CHECK (
                    household_size BETWEEN 1 AND 100
                    AND children_count >= 0
                    AND elderly_count >= 0
                    AND disabled_count >= 0
                    AND pregnant_lactating_count >= 0
                    AND children_count <= household_size
                    AND elderly_count <= household_size
                    AND disabled_count <= household_size
                    AND pregnant_lactating_count <= household_size
                    AND children_count + elderly_count <= household_size
                )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE beneficiaries
                ADD CONSTRAINT beneficiaries_national_id_pair_check
                CHECK ((national_id IS NULL) = (national_id_hash IS NULL))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE beneficiaries
                ADD CONSTRAINT beneficiaries_national_id_hash_format_check
                CHECK (national_id_hash IS NULL OR national_id_hash ~ '^[0-9a-f]{64}$')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE beneficiaries
                ADD CONSTRAINT beneficiaries_vulnerability_tags_array_check
                CHECK (jsonb_typeof(vulnerability_tags) = 'array')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE beneficiaries
                ADD CONSTRAINT beneficiaries_revision_check
                CHECK (revision >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiaries');
    }
};
