<?php

declare(strict_types=1);

namespace App\Services\Sync;

/**
 * Machine-readable reasons an item was not stored.
 */
enum FailureCode: string
{
    /** Referenced beneficiary is unknown (or failed earlier in this batch). */
    case BeneficiaryNotFound = 'beneficiary_not_found';

    /** Referenced assessment is unknown (or failed earlier in this batch). */
    case AssessmentNotFound = 'assessment_not_found';

    /** Another active household in the organization already uses this national ID. */
    case NationalIdAlreadyRegistered = 'national_id_already_registered';

    /** An image client_uuid was re-sent with a different hash or parent assessment. */
    case ImageImmutable = 'image_immutable';

    /** Inline content does not decode as base64. */
    case InvalidImageContent = 'invalid_image_content';

    /** Inline content does not match the declared sha256. */
    case ImageHashMismatch = 'image_hash_mismatch';

    /** Content is not an allowed image type, or differs from the declared mime_type. */
    case UnsupportedMediaType = 'unsupported_media_type';

    /** Decoded content exceeds the configured size limit or differs from size_bytes. */
    case ImageSizeMismatch = 'image_size_mismatch';

    /** A concurrent batch wrote the same record first; resending resolves it. */
    case ConcurrentWrite = 'concurrent_write';

    /** A database integrity rule rejected the record. */
    case ConstraintViolation = 'constraint_violation';

    /** Binary storage was unavailable. */
    case StorageUnavailable = 'storage_unavailable';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::BeneficiaryNotFound,
            self::AssessmentNotFound,
            self::ConcurrentWrite,
            self::StorageUnavailable => true,
            self::NationalIdAlreadyRegistered,
            self::ImageImmutable,
            self::InvalidImageContent,
            self::ImageHashMismatch,
            self::UnsupportedMediaType,
            self::ImageSizeMismatch,
            self::ConstraintViolation => false,
        };
    }
}
