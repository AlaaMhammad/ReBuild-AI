<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SyncBatchStatus;
use App\Models\Organization;
use App\Models\SyncBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SyncBatch>
 */
final class SyncBatchFactory extends Factory
{
    protected $model = SyncBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $receivedAt = now();

        return [
            'organization_id' => Organization::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random(128)),
            'device_id' => 'android-'.Str::lower(Str::random(16)),
            'device_platform' => 'android',
            'app_version' => '1.0.0',
            'status' => SyncBatchStatus::Received,
            'total_items' => fake()->numberBetween(1, 50),
            'client_sent_at' => $receivedAt->subSeconds(fake()->numberBetween(1, 120)),
            'received_at' => $receivedAt,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'ReBuildAI-Field/1.0.0 (Android 14)',
        ];
    }

    public function fromUser(User $user): self
    {
        return $this->state([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
        ]);
    }
}
