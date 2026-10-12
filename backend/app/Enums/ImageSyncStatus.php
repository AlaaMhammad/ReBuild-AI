<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ImageSyncStatus: string
{
    use HasValues;

    case PendingUpload = 'pending_upload';
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';

    public function hasStoredFile(): bool
    {
        return match ($this) {
            self::Uploaded, self::Processing, self::Processed => true,
            self::PendingUpload, self::Failed => false,
        };
    }
}
