<?php

declare(strict_types=1);

namespace App\Services\Sync;

/**
 * Why an item was acknowledged without being written. All skipped items are
 * final from the device's point of view: it must not resend them.
 */
enum SkipReason: string
{
    /** The server already holds this exact revision (e.g. a re-batched retry). */
    case Unchanged = 'unchanged';

    /** The server holds a newer revision than the one sent. */
    case StaleRevision = 'stale_revision';

    /** A coordinator has started reviewing the record; server state wins. */
    case LockedByReview = 'locked_by_review';

    /** The record was deleted on the server and is not resurrected by sync. */
    case DeletedOnServer = 'deleted_on_server';
}
