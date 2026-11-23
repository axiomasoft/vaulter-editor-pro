<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\EditorPro\Review\Data\RevisionPinData;
use Vaulter\EditorPro\Review\Data\RevisionPreviewData;
use Vaulter\EditorPro\Review\Support\ReviewTags;

/** JSON representation of a read-only immutable revision preview, with URLs sanitized. */
#[Internal]
final class RevisionPreviewResource extends JsonResource
{
    public function __construct(RevisionPreviewData $preview)
    {
        parent::__construct($preview);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var RevisionPreviewData $preview */
        $preview = $this->resource;

        return [
            'revision' => self::formatMeta($preview->revision),
            'pin' => $preview->pin === null ? null : (new RevisionPinResource($preview->pin))->toArray($request),
            'content' => self::sanitizeContent($preview->content),
        ];
    }

    /** @return array<string, mixed> */
    public static function formatMeta(DocumentRevisionSnapshot|DocumentSnapshot $target): array
    {
        if ($target instanceof DocumentRevisionSnapshot) {
            return [
                'kind' => 'revision',
                'id' => $target->id,
                'document_id' => $target->documentId,
                'node_id' => $target->nodeId,
                'type' => $target->type,
                'number' => $target->number,
                'schema_version' => $target->schemaVersion,
                'state_version' => $target->stateVersion,
                'collab_epoch' => $target->epoch,
                'label' => $target->label,
                'is_autosave' => $target->isAutosave,
                'keep_forever' => $target->keepForever,
                'content_hash' => $target->contentHash,
                'created_by' => $target->createdBy,
                'created_at' => ReviewTags::iso($target->createdAt),
            ];
        }

        return [
            'kind' => 'working',
            'id' => $target->headRevisionId,
            'document_id' => $target->documentId,
            'node_id' => $target->nodeId,
            'type' => $target->type,
            'schema_version' => $target->schemaVersion,
            'state_version' => $target->stateVersion,
            'collab_epoch' => $target->epoch,
            'content_hash' => $target->contentHash,
            'updated_by' => $target->updatedBy,
            'updated_at' => ReviewTags::iso($target->updatedAt),
        ];
    }

    /** @return array<string, mixed> */
    public static function sanitizeContent(DocumentContent $content): array
    {
        return $content instanceof TipTapContent ? $content->withSanitizedUrls()->toArray() : $content->toArray();
    }

    public static function formatPin(?RevisionPinData $pin, Request $request): mixed
    {
        return $pin === null ? null : (new RevisionPinResource($pin))->toArray($request);
    }
}
