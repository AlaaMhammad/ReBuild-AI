<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Enums\ClientType;
use App\Models\User;
use Database\Factories\BeneficiaryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class SyncTestCase extends TestCase
{
    use RefreshDatabase;

    protected const DEVICE_ID = 'android-sync-device-0001';

    /**
     * A valid 1×1 PNG.
     */
    protected const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected User $agent;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 08:00:00');
        Storage::fake('local');
        config(['rebuild.media.disk' => 'local']);

        $this->agent = User::factory()->fieldAgent()->create();
        $this->token = $this->issueToken($this->agent, ClientType::FieldDevice, self::DEVICE_ID);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function pushBatch(array $payload, ?string $token = null): TestResponse
    {
        $this->forgetAuthenticatedUser();

        return $this->withToken($token ?? $this->token)->postJson('/api/v1/sync/batch', $payload);
    }

    /**
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    protected function batch(array $sections, array $overrides = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'sent_at' => now()->toIso8601String(),
            'device' => ['id' => self::DEVICE_ID, 'platform' => 'android', 'app_version' => '1.4.2'],
            ...$sections,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function beneficiaryItem(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'revision' => 1,
            'national_id' => BeneficiaryFactory::fakeNationalId(),
            'full_name' => 'أحمد إبراهيم أبو شعبان',
            'gender' => 'male',
            'governorate' => 'khan_yunis',
            'displacement_status' => 'displaced_tent',
            'household_size' => 7,
            'children_count' => 4,
            'elderly_count' => 1,
            'vulnerability_tags' => ['chronic_illness'],
            'client_created_at' => now()->subHour()->toIso8601String(),
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function assessmentItem(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'revision' => 1,
            'location' => ['lat' => 31.3462, 'lng' => 34.3063, 'accuracy_m' => 6.5],
            'governorate' => 'khan_yunis',
            'structure_type' => 'residential_house',
            'damage_tier' => 'severe',
            'habitability' => 'uninhabitable',
            'floors_count' => 3,
            'urgent_needs' => [['category' => 'shelter', 'quantity' => 1], ['category' => 'water']],
            'assessed_at' => now()->subMinutes(30)->toIso8601String(),
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function imageItem(string $assessmentClientUuid, array $overrides = []): array
    {
        $bytes = (string) base64_decode(self::PNG_BASE64, true);

        return [
            'client_uuid' => (string) Str::uuid(),
            'assessment_client_uuid' => $assessmentClientUuid,
            'sha256' => hash('sha256', $bytes),
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'captured_at' => now()->subMinutes(29)->toIso8601String(),
            ...$overrides,
        ];
    }

    protected function pngBytes(): string
    {
        return (string) base64_decode(self::PNG_BASE64, true);
    }
}
