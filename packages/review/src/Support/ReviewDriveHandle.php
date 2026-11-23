<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Support;

use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Vaulter;

/** Resolves an actor-bound `DriveHandle` from a `DriveContext`. */
#[Internal]
final class ReviewDriveHandle
{
    public static function fromContext(
        Vaulter $vaulter,
        DriveContext $context,
        bool $withIdempotency = false,
    ): DriveHandle {
        $drive = $vaulter->findDriveById($context->operation->scope->id);
        if ($drive === null) {
            throw DriveArchivedException::forDrive($context->operation->scope->id);
        }

        $actor = $context->actor;
        if ($actor->isSystem()) {
            $drive = $drive->asSystem($actor->ref()->reason ?? 'review');
        } elseif ($actor->authorizable() !== null) {
            $drive = $drive->actingAs($actor->authorizable());
        }

        $drive = $drive->withCorrelationId($context->correlationId);

        if ($withIdempotency && $context->operation->idempotencyKey !== null) {
            $drive = $drive->withIdempotencyKey($context->operation->idempotencyKey, $context->operation->requestFingerprint);
        }

        return $drive;
    }
}
