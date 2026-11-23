<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\RevisionPinData;
use Vaulter\EditorPro\Review\Support\ReviewTags;

/** JSON representation of a `v_document_revision_pins` record. */
#[Internal]
final class RevisionPinResource extends JsonResource
{
    public function __construct(RevisionPinData $pin)
    {
        parent::__construct($pin);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var RevisionPinData $pin */
        $pin = $this->resource;

        return [
            'revision_id' => $pin->revisionId,
            'document_id' => $pin->documentId,
            'pin_reason' => $pin->pinReason->value,
            'source_generation' => $pin->sourceGeneration,
            'source_position' => $pin->sourcePosition,
            'pinned_by_subject' => $pin->pinnedBySubject,
            'pinned_at' => ReviewTags::iso($pin->pinnedAt),
            'expires_at' => ReviewTags::iso($pin->expiresAt),
        ];
    }
}
