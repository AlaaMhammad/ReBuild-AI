<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Exceptions\TenantViolationException;
use App\Models\DamageAssessment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $alpha;

    private Organization $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Organization::factory()->create();
        $this->beta = Organization::factory()->create();

        DamageAssessment::factory()->count(3)->for($this->alpha)->create();
        DamageAssessment::factory()->count(2)->for($this->beta)->create();
    }

    #[Test]
    public function reads_are_scoped_to_the_active_tenant(): void
    {
        $this->tenant()->set($this->alpha);

        self::assertSame(3, DamageAssessment::query()->count());
        self::assertSame([$this->alpha->id], DamageAssessment::query()->distinct()->pluck('organization_id')->all());
    }

    #[Test]
    public function finding_another_tenants_record_by_key_returns_nothing(): void
    {
        $betaRecord = DamageAssessment::query()->forOrganization($this->beta)->firstOrFail();

        $this->tenant()->set($this->alpha);

        self::assertNull(DamageAssessment::query()->find($betaRecord->id));
    }

    #[Test]
    public function without_a_tenant_reads_are_unfiltered_for_system_processes(): void
    {
        self::assertSame(5, DamageAssessment::query()->count());

        $this->tenant()->set($this->alpha);

        self::assertSame(5, $this->tenant()->runWithoutTenant(static fn (): int => DamageAssessment::query()->count()));
        self::assertSame($this->alpha->id, $this->tenant()->id(), 'The previous tenant must be restored.');
    }

    #[Test]
    public function new_records_inherit_the_active_tenant(): void
    {
        $this->tenant()->set($this->alpha);

        $user = User::factory()->make(['organization_id' => null]);
        $user->save();

        self::assertSame($this->alpha->id, $user->organization_id);
    }

    #[Test]
    public function writing_for_another_tenant_is_refused(): void
    {
        $this->tenant()->set($this->alpha);

        $this->expectException(TenantViolationException::class);

        DamageAssessment::factory()->for($this->beta)->create();
    }

    #[Test]
    public function records_cannot_move_between_tenants(): void
    {
        $record = DamageAssessment::query()->forOrganization($this->alpha)->firstOrFail();

        $this->expectException(TenantViolationException::class);

        $record->forceFill(['organization_id' => $this->beta->id])->save();
    }

    #[Test]
    public function a_record_without_any_organization_is_refused(): void
    {
        $this->expectException(TenantViolationException::class);

        DamageAssessment::factory()->make(['organization_id' => null, 'client_uuid' => (string) Str::uuid()])->save();
    }

    #[Test]
    public function run_as_restores_the_previous_context(): void
    {
        $count = $this->tenant()->runAs($this->beta, static fn (): int => DamageAssessment::query()->count());

        self::assertSame(2, $count);
        self::assertFalse($this->tenant()->isSet());
    }
}
