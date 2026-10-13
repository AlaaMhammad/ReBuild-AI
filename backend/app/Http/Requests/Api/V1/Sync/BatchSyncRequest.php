<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sync;

use App\Enums\ClientType;
use App\Enums\DamageTier;
use App\Enums\DisplacementStatus;
use App\Enums\Gender;
use App\Enums\Governorate;
use App\Enums\HabitabilityStatus;
use App\Enums\NeedCategory;
use App\Enums\StructureType;
use App\Enums\TokenAbility;
use App\Enums\VulnerabilityTag;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Rules\PalestinianNationalId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates an offline batch pushed by a field device.
 *
 * Shape (all sections optional, at least one item overall):
 *
 *   idempotency_key  uuid      one key per batch, reused verbatim on every retry
 *   sent_at          ISO-8601  device clock at send time
 *   device           {id, platform?, app_version?}
 *   beneficiaries[]  households, keyed by client_uuid
 *   assessments[]    damage assessments; may reference beneficiary_client_uuid
 *   images[]         photo metadata (+ optional inline content_base64);
 *                    reference assessment_client_uuid
 */
final class BatchSyncRequest extends FormRequest
{
    private const ISO_DATETIME = 'date';

    /**
     * Section-level authorization on top of the route's sync:push ability:
     * a token may only push the entity types it is allowed to write, and a
     * field-device token may only push batches for its own device.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $required = array_filter([
            $this->filled('beneficiaries') ? TokenAbility::BeneficiariesWrite : null,
            $this->filled('assessments') ? TokenAbility::AssessmentsWrite : null,
            $this->filled('images') ? TokenAbility::ImagesUpload : null,
        ]);

        foreach ($required as $ability) {
            if (! $user->hasAbility($ability)) {
                return false;
            }
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->client_type === ClientType::FieldDevice) {
            return $this->input('device.id') === $token->device_id;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxItems = (int) config('rebuild.sync.max_items_per_batch');
        $maxImageBytes = (int) config('rebuild.media.max_image_bytes');
        $maxInlineBase64Chars = (int) (ceil((int) config('rebuild.sync.max_inline_image_bytes') / 3) * 4);

        return [
            'idempotency_key' => ['required', 'uuid'],
            'sent_at' => ['required', self::ISO_DATETIME],

            'device' => ['required', 'array:id,platform,app_version'],
            'device.id' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'device.platform' => ['nullable', 'string', Rule::in(['android', 'ios', 'web'])],
            'device.app_version' => ['nullable', 'string', 'max:32'],

            // Beneficiaries ---------------------------------------------------
            'beneficiaries' => ['sometimes', 'array', "max:{$maxItems}"],
            'beneficiaries.*' => ['array'],
            'beneficiaries.*.client_uuid' => ['required', 'uuid', 'distinct'],
            'beneficiaries.*.revision' => ['required', 'integer', 'min:1', 'max:1000000'],
            'beneficiaries.*.national_id' => ['nullable', new PalestinianNationalId],
            'beneficiaries.*.full_name' => ['required', 'string', 'min:2', 'max:255'],
            'beneficiaries.*.gender' => ['nullable', Rule::enum(Gender::class)],
            'beneficiaries.*.date_of_birth' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01'],
            'beneficiaries.*.phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'beneficiaries.*.governorate' => ['nullable', Rule::enum(Governorate::class)],
            'beneficiaries.*.displacement_status' => ['nullable', Rule::enum(DisplacementStatus::class)],
            'beneficiaries.*.address_description' => ['nullable', 'string', 'max:2000'],
            'beneficiaries.*.household_size' => ['required', 'integer', 'min:1', 'max:100'],
            'beneficiaries.*.children_count' => ['sometimes', 'integer', 'min:0', 'lte:beneficiaries.*.household_size'],
            'beneficiaries.*.elderly_count' => ['sometimes', 'integer', 'min:0', 'lte:beneficiaries.*.household_size'],
            'beneficiaries.*.disabled_count' => ['sometimes', 'integer', 'min:0', 'lte:beneficiaries.*.household_size'],
            'beneficiaries.*.pregnant_lactating_count' => ['sometimes', 'integer', 'min:0', 'lte:beneficiaries.*.household_size'],
            'beneficiaries.*.is_female_headed' => ['sometimes', 'boolean'],
            'beneficiaries.*.vulnerability_tags' => ['sometimes', 'array', 'max:'.count(VulnerabilityTag::cases())],
            'beneficiaries.*.vulnerability_tags.*' => ['distinct', Rule::enum(VulnerabilityTag::class)],
            'beneficiaries.*.notes' => ['nullable', 'string', 'max:5000'],
            'beneficiaries.*.client_created_at' => ['nullable', self::ISO_DATETIME],
            'beneficiaries.*.client_updated_at' => ['nullable', self::ISO_DATETIME],

            // Assessments -----------------------------------------------------
            'assessments' => ['sometimes', 'array', "max:{$maxItems}"],
            'assessments.*' => ['array'],
            'assessments.*.client_uuid' => ['required', 'uuid', 'distinct'],
            'assessments.*.revision' => ['required', 'integer', 'min:1', 'max:1000000'],
            'assessments.*.beneficiary_client_uuid' => ['nullable', 'uuid'],
            'assessments.*.location' => ['required', 'array:lat,lng,accuracy_m'],
            'assessments.*.location.lat' => ['required', 'numeric', 'between:-90,90'],
            'assessments.*.location.lng' => ['required', 'numeric', 'between:-180,180'],
            'assessments.*.location.accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'assessments.*.governorate' => ['nullable', Rule::enum(Governorate::class)],
            'assessments.*.address_description' => ['nullable', 'string', 'max:2000'],
            'assessments.*.structure_type' => ['required', Rule::enum(StructureType::class)],
            'assessments.*.damage_tier' => ['required', Rule::enum(DamageTier::class)],
            'assessments.*.habitability' => ['required', Rule::enum(HabitabilityStatus::class)],
            'assessments.*.floors_count' => ['nullable', 'integer', 'min:0', 'max:200'],
            'assessments.*.units_count' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'assessments.*.urgent_needs' => ['sometimes', 'array', 'max:'.count(NeedCategory::cases())],
            'assessments.*.urgent_needs.*' => ['array:category,quantity,notes'],
            // Uniqueness of categories is per assessment (checked in after()); the
            // "distinct" rule would compare across every assessment in the batch.
            'assessments.*.urgent_needs.*.category' => ['required', Rule::enum(NeedCategory::class)],
            'assessments.*.urgent_needs.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'assessments.*.urgent_needs.*.notes' => ['nullable', 'string', 'max:500'],
            'assessments.*.notes' => ['nullable', 'string', 'max:5000'],
            'assessments.*.assessed_at' => ['required', self::ISO_DATETIME],
            'assessments.*.client_created_at' => ['nullable', self::ISO_DATETIME],
            'assessments.*.client_updated_at' => ['nullable', self::ISO_DATETIME],

            // Images ----------------------------------------------------------
            'images' => ['sometimes', 'array', "max:{$maxItems}"],
            'images.*' => ['array'],
            'images.*.client_uuid' => ['required', 'uuid', 'distinct'],
            'images.*.assessment_client_uuid' => ['required', 'uuid'],
            'images.*.sha256' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'images.*.mime_type' => ['required', 'string', Rule::in(config('rebuild.media.allowed_mime_types'))],
            'images.*.size_bytes' => ['required', 'integer', 'min:1', "max:{$maxImageBytes}"],
            'images.*.width' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'images.*.height' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'images.*.original_filename' => ['nullable', 'string', 'max:255'],
            'images.*.captured_at' => ['nullable', self::ISO_DATETIME],
            'images.*.location' => ['nullable', 'array:lat,lng'],
            'images.*.location.lat' => ['required_with:images.*.location', 'numeric', 'between:-90,90'],
            'images.*.location.lng' => ['required_with:images.*.location', 'numeric', 'between:-180,180'],
            'images.*.caption' => ['nullable', 'string', 'max:1000'],
            'images.*.content_base64' => ['nullable', 'string', "max:{$maxInlineBase64Chars}"],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $counts = [
                    'beneficiaries' => count((array) $this->input('beneficiaries', [])),
                    'assessments' => count((array) $this->input('assessments', [])),
                    'images' => count((array) $this->input('images', [])),
                ];
                $total = array_sum($counts);
                $max = (int) config('rebuild.sync.max_items_per_batch');

                if ($total === 0) {
                    $validator->errors()->add('batch', 'The batch must contain at least one beneficiary, assessment or image.');
                }

                if ($total > $max) {
                    $validator->errors()->add('batch', "The batch may contain at most {$max} items in total; split it into smaller batches.");
                }

                $inline = collect((array) $this->input('images', []))
                    ->filter(static fn (mixed $image): bool => is_array($image) && filled($image['content_base64'] ?? null))
                    ->count();
                $maxInline = (int) config('rebuild.sync.max_inline_images_per_batch');

                if ($inline > $maxInline) {
                    $validator->errors()->add('images', "At most {$maxInline} images may carry inline content per batch; upload the rest through the image content endpoint.");
                }

                foreach ((array) $this->input('assessments', []) as $index => $assessment) {
                    $categories = array_column(array_filter((array) (is_array($assessment) ? ($assessment['urgent_needs'] ?? []) : []), 'is_array'), 'category');

                    if (count($categories) !== count(array_unique($categories, SORT_REGULAR))) {
                        $validator->errors()->add(
                            "assessments.{$index}.urgent_needs",
                            'Each need category may appear only once per assessment.',
                        );
                    }
                }

                foreach ((array) $this->input('beneficiaries', []) as $index => $beneficiary) {
                    if (! is_array($beneficiary)) {
                        continue;
                    }

                    $size = (int) ($beneficiary['household_size'] ?? 0);
                    $children = (int) ($beneficiary['children_count'] ?? 0);
                    $elderly = (int) ($beneficiary['elderly_count'] ?? 0);

                    if ($size > 0 && $children + $elderly > $size) {
                        $validator->errors()->add(
                            "beneficiaries.{$index}.household_size",
                            'children_count + elderly_count cannot exceed household_size.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'device.id.regex' => 'The device id may only contain letters, digits, ".", "_", ":" and "-".',
            '*.*.client_uuid.distinct' => 'Each client_uuid may appear only once per section of a batch.',
        ];
    }
}
