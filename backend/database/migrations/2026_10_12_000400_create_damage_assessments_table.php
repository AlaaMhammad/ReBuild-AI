<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structural damage assessments captured offline by field agents.
 *
 * - client_uuid is generated on the device and is the idempotency anchor for sync.
 * - location is geography(Point, 4326): distances are computed in metres on the
 *   WGS84 spheroid, and the GiST index serves ST_DWithin, bbox (&&) and KNN (<->).
 * - priority_*, duplicate_of_id and review columns are populated server-side by
 *   the triage and deduplication pipeline; devices never write them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damage_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignUuid('sync_batch_id')
                ->nullable()
                ->constrained('sync_batches')
                ->nullOnDelete();
            $table->foreignUuid('beneficiary_id')
                ->nullable()
                ->constrained('beneficiaries')
                ->nullOnDelete();
            $table->foreignUuid('assessor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->geography('location', subtype: 'point', srid: 4326);
            $table->decimal('location_accuracy_m', 8, 2)->nullable();
            $table->string('governorate', 32)->nullable();
            $table->text('address_description')->nullable();

            $table->string('structure_type', 32);
            $table->string('damage_tier', 16);
            $table->string('habitability', 32);
            $table->unsignedSmallInteger('floors_count')->nullable();
            $table->unsignedSmallInteger('units_count')->nullable();
            $table->jsonb('urgent_needs')->default(DB::raw("'[]'::jsonb"));
            $table->text('notes')->nullable();

            $table->string('status', 32)->default('submitted');
            $table->decimal('priority_score', 6, 2)->nullable();
            $table->string('priority_level', 16)->nullable();
            $table->timestampTz('priority_calculated_at')->nullable();
            $table->uuid('duplicate_of_id')->nullable();
            $table->foreignUuid('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();

            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('assessed_at');
            $table->timestampTz('client_created_at')->nullable();
            $table->timestampTz('client_updated_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'client_uuid']);
            $table->spatialIndex('location');
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'assessed_at']);
            $table->index(['organization_id', 'priority_level', 'priority_score']);
            $table->index(['damage_tier', 'governorate']);
            $table->index('beneficiary_id');
            $table->index('assessor_id');
            $table->index('duplicate_of_id');
            $table->index('urgent_needs', null, 'gin');
        });

        // Self-reference is added after creation: inside Schema::create the
        // foreign key would be compiled before the primary key exists.
        Schema::table('damage_assessments', function (Blueprint $table): void {
            $table->foreign('duplicate_of_id')
                ->references('id')
                ->on('damage_assessments')
                ->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE damage_assessments
                ADD CONSTRAINT damage_assessments_structure_type_check
                CHECK (structure_type IN (
                    'residential_house', 'apartment_building', 'shelter', 'school', 'health_facility',
                    'commercial', 'religious', 'infrastructure', 'other'
                ))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE damage_assessments
                ADD CONSTRAINT damage_assessments_damage_tier_check
                CHECK (damage_tier IN ('none', 'minor', 'moderate', 'severe', 'destroyed'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE damage_assessments
                ADD CONSTRAINT damage_assessments_habitability_check
                CHECK (habitability IN ('habitable', 'partially_habitable', 'uninhabitable'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE damage_assessments
                ADD CONSTRAINT damage_assessments_status_check
                CHECK (status IN ('submitted', 'under_review', 'verified', 'rejected', 'duplicate'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE damage_assessments
                ADD CONSTRAINT damage_assessments_priority_check
                CHECK (
                    (priority_level IS NULL OR priority_level IN ('critical', 'high', 'medium', 'low'))
                    AND (priority_score IS NULL OR priority_score BETWEEN 0 AND 100)
                )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE damage_assessments
                ADD CONSTRAINT damage_assessments_governorate_check
                CHECK (governorate IS NULL OR governorate IN ('north_gaza', 'gaza', 'deir_al_balah', 'khan_yunis', 'rafah'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE damage_assessments
                ADD CONSTRAINT damage_assessments_duplicate_consistency_check
                CHECK (
                    (status = 'duplicate') = (duplicate_of_id IS NOT NULL)
                    AND (duplicate_of_id IS NULL OR duplicate_of_id <> id)
                )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE damage_assessments
                ADD CONSTRAINT damage_assessments_numeric_ranges_check
                CHECK (
                    (location_accuracy_m IS NULL OR location_accuracy_m >= 0)
                    AND (floors_count IS NULL OR floors_count BETWEEN 0 AND 200)
                    AND (units_count IS NULL OR units_count BETWEEN 0 AND 2000)
                    AND revision >= 1
                )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE damage_assessments
                ADD CONSTRAINT damage_assessments_urgent_needs_array_check
                CHECK (jsonb_typeof(urgent_needs) = 'array')
            SQL);

        // The geography type already rejects out-of-range coordinates; this
        // additionally rejects 'POINT EMPTY', which a device may emit without a GPS fix.
        DB::statement(<<<'SQL'
            ALTER TABLE damage_assessments
                ADD CONSTRAINT damage_assessments_location_not_empty_check
                CHECK (NOT ST_IsEmpty(location::geometry))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_assessments');
    }
};
