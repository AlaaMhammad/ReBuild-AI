<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\ImageSyncStatus;
use App\Models\AssessmentImage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Post-upload indexing of an assessment image:
 *  - re-verifies the stored bytes against the declared sha256 (integrity at rest);
 *  - confirms the bytes decode as an image and records the real dimensions;
 *  - moves the image to "processed".
 *
 * Permanent problems (corrupt or tampered file) mark the image "failed" so the
 * device re-uploads it; transient storage errors are rethrown for the queue
 * to retry with backoff.
 */
final class AssessmentImageProcessor
{
    public function process(AssessmentImage $image): void
    {
        if (! in_array($image->sync_status, [ImageSyncStatus::Uploaded, ImageSyncStatus::Processing], true)) {
            return;
        }

        $image->forceFill(['sync_status' => ImageSyncStatus::Processing])->save();

        try {
            $bytes = Storage::disk($image->disk)->get((string) $image->storage_path);
        } catch (Throwable $exception) {
            $image->forceFill(['sync_status' => ImageSyncStatus::Uploaded])->save();

            throw $exception;
        }

        if ($bytes === null) {
            $this->fail($image, 'Stored file is missing; re-upload required.');

            return;
        }

        if (! hash_equals($image->sha256, hash('sha256', $bytes))) {
            $this->fail($image, 'Stored file no longer matches its sha256; re-upload required.');

            return;
        }

        $dimensions = @getimagesizefromstring($bytes);

        if ($dimensions === false) {
            $this->fail($image, 'Stored file could not be decoded as an image; re-upload required.');

            return;
        }

        $image->forceFill([
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'sync_status' => ImageSyncStatus::Processed,
            'processed_at' => CarbonImmutable::now(),
            'last_sync_error' => null,
        ])->save();
    }

    private function fail(AssessmentImage $image, string $reason): void
    {
        $path = $image->storage_path;
        $disk = $image->disk;

        // A failed image is re-uploadable; the stored-file CHECK constraint only
        // requires storage_path for uploaded/processing/processed rows.
        $image->forceFill(['storage_path' => null, 'uploaded_at' => null])->markFailed($reason);

        if ($path !== null) {
            Storage::disk($disk)->delete($path);
        }
    }
}
