<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Contracts;

use Packman\Attributes\Internal;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;

/**
 * Internal seam invoked right after an exchange artifact is committed to Media
 * and before the exchange job row is locked/updated & pinned.
 */
#[Internal]
interface BeforeArtifactLinkHook
{
    public function beforeLinkArtifact(string $jobId, BlobSnapshot $artifact): void;
}
