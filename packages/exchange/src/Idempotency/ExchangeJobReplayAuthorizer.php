<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Idempotency;

use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Exchange\Http\Support\ExchangeDriveHandle;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;

/** Re-authorizes idempotent replay for exchange job registrations against current ACL. */
#[Internal]
final readonly class ExchangeJobReplayAuthorizer implements ReplayAuthorizer
{
    public function __construct(
        private DriveContext $context,
        private Vaulter $vaulter,
        private string $nodeId,
        private Ability $requiredAbility,
    ) {}

    public function scope(mixed $result): ResourceRef
    {
        return new ResourceRef('node', $this->nodeId);
    }

    public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void
    {
        $targetNodeId = $resource?->kind === 'node' ? $resource->id : ($scope?->kind === 'node' ? $scope->id : $this->nodeId);
        $drive = ExchangeDriveHandle::fromContext($this->vaulter, $this->context);
        if ($drive->snapshot()->status !== DriveStatus::Active) {
            throw DriveArchivedException::forDrive($drive->snapshot()->id);
        }

        $drive->nodes()->find($targetNodeId);
        $abilities = $drive->nodes()->abilities($targetNodeId)->flags;
        if (! ($abilities[$this->requiredAbility->value] ?? false)) {
            throw PermissionDeniedException::forAbility($this->requiredAbility->value);
        }
    }
}
