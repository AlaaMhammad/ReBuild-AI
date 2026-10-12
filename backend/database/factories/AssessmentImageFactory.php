<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImageSyncStatus;
use App\Models\AssessmentImage;
use App\Models\DamageAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssessmentImage>
 */
final class AssessmentImageFactory extends Factory
{
    protected $model = AssessmentImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'damage_assessment_id' => DamageAssessment::factory(),
            'organization_id' => static fn (array $attributes): string => DamageAssessment::query()
                ->withoutGlobalScopes()
                ->findOrFail($attributes['damage_assessment_id'])
                ->organization_id,
            'client_uuid' => (string) Str::uuid(),
            'disk' => 'local',
            'original_filename' => 'IMG_'.fake()->numerify('########').'.jpg',
            'mime_type' => 'image/jpeg',
            'sha256' => hash('sha256', Str::random(64)),
            'width' => 4032,
            'height' => 3024,
            'captured_at' => now()->subHours(fake()->numberBetween(1, 48)),
            'sync_status' => ImageSyncStatus::PendingUpload,
        ];
    }

    public function uploaded(): self
    {
        return $this->state(fn (array $attributes): array => [
            'sync_status' => ImageSyncStatus::Uploaded,
            'storage_path' => 'assessments/'.Str::uuid().'.jpg',
            'size_bytes' => fake()->numberBetween(200_000, 4_000_000),
            'uploaded_at' => now(),
        ]);
    }
}
