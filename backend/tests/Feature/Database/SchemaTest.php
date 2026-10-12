<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\AssessmentStatus;
use App\Models\AssessmentImage;
use App\Models\Beneficiary;
use App\Models\DamageAssessment;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function required_extensions_are_installed(): void
    {
        $extensions = DB::table('pg_extension')->pluck('extname')->all();

        self::assertContains('postgis', $extensions);
        self::assertContains('pg_trgm', $extensions);
    }

    #[Test]
    public function location_columns_are_wgs84_geography_points(): void
    {
        foreach (['damage_assessments', 'assessment_images'] as $table) {
            $type = DB::selectOne(
                "SELECT format_type(atttypid, atttypmod) AS type FROM pg_attribute WHERE attrelid = ?::regclass AND attname = 'location'",
                [$table],
            );

            self::assertSame('geography(Point,4326)', $type->type, $table);
        }
    }

    #[Test]
    public function spatial_trigram_and_partial_unique_indexes_exist(): void
    {
        $indexes = DB::table('pg_indexes')
            ->whereIn('tablename', ['damage_assessments', 'beneficiaries'])
            ->pluck('indexdef', 'indexname');

        self::assertStringContainsString('USING gist (location)', (string) $indexes['damage_assessments_location_spatialindex']);
        self::assertStringContainsString('gin_trgm_ops', (string) $indexes['beneficiaries_full_name_normalized_trgm_index']);
        self::assertStringContainsString('WHERE ((national_id_hash IS NOT NULL) AND (deleted_at IS NULL))', (string) $indexes['beneficiaries_org_national_id_hash_active_unique']);
    }

    #[Test]
    public function the_damage_tier_check_constraint_rejects_unknown_values(): void
    {
        $assessment = DamageAssessment::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('damage_assessments_damage_tier_check');

        DB::table('damage_assessments')->where('id', $assessment->id)->update(['damage_tier' => 'catastrophic']);
    }

    #[Test]
    public function a_duplicate_status_requires_a_duplicate_reference(): void
    {
        $assessment = DamageAssessment::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('damage_assessments_duplicate_consistency_check');

        DB::table('damage_assessments')->where('id', $assessment->id)->update(['status' => AssessmentStatus::Duplicate->value]);
    }

    #[Test]
    public function an_empty_point_is_rejected(): void
    {
        $assessment = DamageAssessment::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('damage_assessments_location_not_empty_check');

        DB::statement("UPDATE damage_assessments SET location = 'SRID=4326;POINT EMPTY'::geography WHERE id = ?", [$assessment->id]);
    }

    #[Test]
    public function client_uuids_are_unique_per_organization_only(): void
    {
        $first = DamageAssessment::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $acrossTenants = DamageAssessment::factory()->create([
            'organization_id' => $otherOrganization->id,
            'client_uuid' => $first->client_uuid,
        ]);
        self::assertNotSame($first->id, $acrossTenants->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('damage_assessments_organization_id_client_uuid_unique');

        DamageAssessment::factory()->create([
            'organization_id' => $first->organization_id,
            'client_uuid' => $first->client_uuid,
        ]);
    }

    #[Test]
    public function an_image_cannot_be_marked_uploaded_without_a_stored_file(): void
    {
        $image = AssessmentImage::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('assessment_images_stored_file_check');

        DB::table('assessment_images')->where('id', $image->id)->update(['sync_status' => 'uploaded']);
    }

    #[Test]
    public function household_metrics_must_be_consistent(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('beneficiaries_household_metrics_check');

        Beneficiary::factory()->create([
            'household_size' => 3,
            'children_count' => 2,
            'elderly_count' => 2,
        ]);
    }

    #[Test]
    public function deleting_an_assessment_cascades_to_its_images(): void
    {
        $image = AssessmentImage::factory()->create();

        DB::table('damage_assessments')->where('id', $image->damage_assessment_id)->delete();

        self::assertDatabaseMissing('assessment_images', ['id' => $image->id]);
    }
}
