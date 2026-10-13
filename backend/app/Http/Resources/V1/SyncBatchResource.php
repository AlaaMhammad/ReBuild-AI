<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\ImageSyncStatus;
use App\Models\AssessmentImage;
use App\Models\SyncBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The sync acknowledgement returned for a new batch, a replay, or a status poll.
 * Item-level results come from the stored result_summary, so a replay returns
 * the same acknowledgement as the original response.
 *
 * @mixin SyncBatch
 */
final class SyncBatchResource extends JsonResource
{
    private bool $replayed = false;

    public function replayed(bool $replayed): self
    {
        $this->replayed = $replayed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = $this->result_summary ?? [];

        return [
            'batch_id' => $this->id,
            'idempotency_key' => $this->idempotency_key,
            'status' => $this->status->value,
            'replayed' => $this->replayed,
            'received_at' => $this->received_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'clock' => $summary['clock'] ?? ['skew_seconds' => $this->clock_skew_seconds, 'corrected' => false],
            'counts' => [
                'total' => $this->total_items,
                'created' => $this->created_count,
                'updated' => $this->updated_count,
                'skipped' => $this->skipped_count,
                'failed' => $this->failed_count,
            ],
            'acknowledged_client_uuids' => $summary['acknowledged_client_uuids'] ?? [],
            'results' => $summary['results'] ?? [],
            'id_map' => $summary['id_map'] ?? [],
            'images_awaiting_upload' => $this->currentlyAwaitingUpload($summary['images_awaiting_upload'] ?? []),
        ];
    }

    /**
     * Re-evaluated on every read: binaries may have arrived since the batch.
     *
     * @param  list<string>  $clientUuids
     * @return list<string>
     */
    private function currentlyAwaitingUpload(array $clientUuids): array
    {
        if ($clientUuids === []) {
            return [];
        }

        return AssessmentImage::query()
            ->forOrganization($this->organization_id)
            ->whereIn('client_uuid', $clientUuids)
            ->whereIn('sync_status', [ImageSyncStatus::PendingUpload->value, ImageSyncStatus::Failed->value])
            ->pluck('client_uuid')
            ->values()
            ->all();
    }
}
