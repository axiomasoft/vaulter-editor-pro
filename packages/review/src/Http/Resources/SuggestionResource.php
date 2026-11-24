<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
use Vaulter\EditorPro\Review\Support\ReviewTags;

/** JSON representation of a text or structural review suggestion. */
#[Internal]
final class SuggestionResource extends JsonResource
{
    public function __construct(TextSuggestion $suggestion)
    {
        parent::__construct($suggestion);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var TextSuggestion $s */
        $s = $this->resource;

        return [
            'id' => $s->id,
            'group_id' => $s->groupId,
            'proposal_id' => $s->proposalId,
            'document_id' => $s->documentId,
            'generation' => $s->generation,
            'base_position' => $s->basePosition,
            'schema_version' => $s->schemaVersion,
            'author_subject' => $s->authorSubject,
            'kind' => $s->kind,
            'target_block_id' => $s->targetBlockId,
            'target_selector' => $s->targetSelector,
            'payload' => $s->payload,
            'from' => $s->from,
            'to' => $s->to,
            'parent_id' => $s->parentId,
            'conflicted' => $s->conflicted,
            'conflict_reason' => $s->conflictReason,
            'conflict_with_id' => $s->conflictWithId,
            'before' => $s->before,
            'after' => $s->after,
            'state' => $s->state,
            'version' => $s->version,
            'etag' => $s->etag(),
            'receipt_id' => $s->receiptId,
            'decided_by_subject' => $s->decidedBySubject,
            'decided_at' => ReviewTags::iso($s->decidedAt),
            'pending_commit' => $s->isPendingJournal(),
            'journal_command_id' => $s->journalCommandId,
            'journal_state' => $s->journalState,
            'committed_position' => $s->committedPosition,
            'generation_changed' => $s->generationChanged,
        ];
    }
}
