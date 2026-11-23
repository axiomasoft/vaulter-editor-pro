<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review;

use Packman\Attributes\Api;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\EditorPro\Review\Operations\AnchoredCommentOperations;
use Vaulter\EditorPro\Review\Operations\RevisionPinOperations;

/**
 * Public entrypoint for Vaulter Editor Pro Review operations on a drive:
 * `ReviewManager::pins($drive)` and `ReviewManager::comments($drive)`.
 */
#[Api(since: '1.0')]
final readonly class ReviewManager
{
    public function pins(DriveHandle $drive): RevisionPinOperations
    {
        return $drive->extension(RevisionPinOperations::class);
    }

    public function comments(DriveHandle $drive): AnchoredCommentOperations
    {
        return $drive->extension(AnchoredCommentOperations::class);
    }
}
