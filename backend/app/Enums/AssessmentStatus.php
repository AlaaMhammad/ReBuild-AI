<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum AssessmentStatus: string
{
    use HasValues;

    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';

    /**
     * Whether a later device sync may still overwrite the record. Once a
     * coordinator has picked it up, server-side state wins.
     */
    public function isEditableFromDevice(): bool
    {
        return $this === self::Submitted;
    }
}
