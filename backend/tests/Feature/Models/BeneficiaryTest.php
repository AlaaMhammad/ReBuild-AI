<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\VulnerabilityTag;
use App\Models\Beneficiary;
use App\Models\Organization;
use App\Support\Identity\NationalIdHasher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BeneficiaryTest extends TestCase
{
    use RefreshDatabase;

    private const NATIONAL_ID = '401234567';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    #[Test]
    public function the_national_id_is_encrypted_at_rest_and_indexed_by_keyed_hash(): void
    {
        $beneficiary = Beneficiary::factory()->for($this->organization)->create(['national_id' => '٤٠١٢٣٤٥٦٧']);

        $raw = DB::table('beneficiaries')->where('id', $beneficiary->id)->first();

        self::assertStringNotContainsString(self::NATIONAL_ID, (string) $raw->national_id);
        self::assertSame(self::NATIONAL_ID, Crypt::decryptString((string) $raw->national_id));
        self::assertSame($this->app->make(NationalIdHasher::class)->hash(self::NATIONAL_ID), $raw->national_id_hash);
        self::assertSame(self::NATIONAL_ID, $beneficiary->refresh()->national_id);
        self::assertSame('*****4567', $beneficiary->masked_national_id);
    }

    #[Test]
    public function lookup_by_national_id_never_needs_decryption(): void
    {
        $target = Beneficiary::factory()->for($this->organization)->create(['national_id' => self::NATIONAL_ID]);
        Beneficiary::factory()->count(3)->for($this->organization)->create();

        self::assertSame([$target->id], Beneficiary::query()->whereNationalId('401-234-567')->pluck('id')->all());
    }

    #[Test]
    public function clearing_the_national_id_clears_its_hash(): void
    {
        $beneficiary = Beneficiary::factory()->for($this->organization)->create(['national_id' => self::NATIONAL_ID]);

        $beneficiary->update(['national_id' => null]);

        self::assertNull(DB::table('beneficiaries')->where('id', $beneficiary->id)->value('national_id_hash'));
    }

    #[Test]
    public function a_person_is_registered_at_most_once_per_organization(): void
    {
        Beneficiary::factory()->for($this->organization)->create(['national_id' => self::NATIONAL_ID]);

        // Another organization may register the same person (resolved by deduplication).
        Beneficiary::factory()->for(Organization::factory())->create(['national_id' => self::NATIONAL_ID]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('beneficiaries_org_national_id_hash_active_unique');

        Beneficiary::factory()->for($this->organization)->create(['national_id' => self::NATIONAL_ID]);
    }

    #[Test]
    public function a_soft_deleted_registration_does_not_block_re_registration(): void
    {
        Beneficiary::factory()->for($this->organization)->create(['national_id' => self::NATIONAL_ID])->delete();

        $again = Beneficiary::factory()->for($this->organization)->create(['national_id' => self::NATIONAL_ID]);

        self::assertTrue($again->exists);
    }

    #[Test]
    public function names_are_normalized_and_fuzzy_searchable(): void
    {
        $target = Beneficiary::factory()->for($this->organization)->create(['full_name' => 'أحمد إبراهيم أبو شعبان']);
        Beneficiary::factory()->for($this->organization)->create(['full_name' => 'سارة يوسف المصري']);

        self::assertSame('احمد ابراهيم ابو شعبان', $target->full_name_normalized);
        self::assertSame(
            [$target->id],
            Beneficiary::query()->whereNameSimilarTo('احمد ابراهيم ابوشعبان', 0.5)->pluck('id')->all(),
        );
    }

    #[Test]
    public function vulnerability_tags_are_queryable_through_the_gin_index(): void
    {
        $widow = Beneficiary::factory()->for($this->organization)->create(['vulnerability_tags' => [VulnerabilityTag::Widow]]);
        Beneficiary::factory()->for($this->organization)->create(['vulnerability_tags' => [VulnerabilityTag::NoIncome]]);

        $ids = Beneficiary::query()->withAnyVulnerability(VulnerabilityTag::Widow, VulnerabilityTag::Orphan)->pluck('id')->all();

        self::assertSame([$widow->id], $ids);
        self::assertTrue($widow->refresh()->vulnerability_tags->contains(VulnerabilityTag::Widow));
    }

    #[Test]
    public function serialization_never_exposes_pii_or_lookup_keys(): void
    {
        $array = Beneficiary::factory()->for($this->organization)->create(['national_id' => self::NATIONAL_ID])->toArray();

        self::assertArrayNotHasKey('national_id', $array);
        self::assertArrayNotHasKey('national_id_hash', $array);
        self::assertArrayNotHasKey('full_name_normalized', $array);
    }
}
