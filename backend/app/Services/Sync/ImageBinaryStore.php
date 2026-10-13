<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\AssessmentImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Verifies image bytes against the metadata the device declared at capture
 * time and writes them to the media disk.
 *
 * Nothing is stored unless all of the following hold:
 *  - size is within the configured limit and equals the declared size_bytes;
 *  - SHA-256 equals the declared sha256;
 *  - the sniffed content type is an allowed image type and equals mime_type.
 */
final class ImageBinaryStore
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @throws ItemRejectedException
     */
    public function decodeBase64(string $base64): string
    {
        $normalized = (string) preg_replace('/\s+/', '', $base64);

        if (str_contains($normalized, ',') && str_starts_with($normalized, 'data:')) {
            $normalized = substr($normalized, (int) strpos($normalized, ',') + 1);
        }

        $bytes = base64_decode($normalized, true);

        if ($bytes === false || $bytes === '') {
            throw new ItemRejectedException(FailureCode::InvalidImageContent, 'content_base64 is not valid base64.');
        }

        return $bytes;
    }

    /**
     * Verifies and stores the bytes, returning the storage path. The caller
     * records the path on the model (AssessmentImage::markUploaded).
     *
     * @throws ItemRejectedException invalid content, or the disk is unavailable (StorageUnavailable)
     */
    public function store(AssessmentImage $image, string $bytes): string
    {
        $this->verify($image, $bytes);

        $path = sprintf(
            'assessments/%s/%s/%s.%s',
            $image->organization_id,
            $image->damage_assessment_id,
            $image->id,
            self::EXTENSIONS[(string) $image->mime_type],
        );

        try {
            $written = Storage::disk($image->disk)->put($path, $bytes);
        } catch (Throwable $exception) {
            report($exception);
            $written = false;
        }

        if (! $written) {
            throw new ItemRejectedException(FailureCode::StorageUnavailable, 'Image storage is temporarily unavailable; resend later.');
        }

        return $path;
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    /**
     * @throws ItemRejectedException
     */
    public function verify(AssessmentImage $image, string $bytes): void
    {
        $size = strlen($bytes);
        $limit = (int) config('rebuild.media.max_image_bytes');

        if ($size > $limit) {
            throw new ItemRejectedException(FailureCode::ImageSizeMismatch, "Image exceeds the {$limit}-byte limit.");
        }

        if ($image->size_bytes !== null && $size !== $image->size_bytes) {
            throw new ItemRejectedException(
                FailureCode::ImageSizeMismatch,
                "Received {$size} bytes but {$image->size_bytes} were declared.",
            );
        }

        if (! hash_equals($image->sha256, hash('sha256', $bytes))) {
            throw new ItemRejectedException(FailureCode::ImageHashMismatch, 'Content does not match the declared sha256.');
        }

        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! is_string($sniffed) || ! array_key_exists($sniffed, self::EXTENSIONS) || ! in_array($sniffed, (array) config('rebuild.media.allowed_mime_types'), true)) {
            throw new ItemRejectedException(FailureCode::UnsupportedMediaType, 'Content is not an allowed image type.');
        }

        if ($image->mime_type !== null && $sniffed !== $image->mime_type) {
            throw new ItemRejectedException(
                FailureCode::UnsupportedMediaType,
                "Content is {$sniffed} but {$image->mime_type} was declared.",
            );
        }
    }
}
