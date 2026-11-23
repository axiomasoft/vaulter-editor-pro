<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Sinks;

use Illuminate\Support\Carbon;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Events\Revisions\DocumentRestored;
use Vaulter\EditorPro\Review\Enums\AnchorState;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventSink;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;

/**
 * Transitions attached comment anchors from earlier generations (`generation < $event->epoch`)
 * to `historical` on `DocumentRestored` or `detached` on cross-generation content replacement
 * inside the exact owner write transaction. Discussions and replies in `v_node_comments` stay intact.
 */
#[Internal]
final readonly class RestoreAnchorTransitionSink implements EventSink
{
    public const string ID = 'review.restore-anchors';

    public function __construct(private DatabaseOptions $tables) {}

    public function record(DomainEvent $event, SinkWriter $writer): SinkResult
    {
        if (! $event instanceof DocumentRestored && ! $event instanceof DocumentContentUpdated) {
            return SinkResult::Skipped;
        }

        $targetState = $event instanceof DocumentRestored
            ? AnchorState::Historical
            : AnchorState::Detached;

        $now = Carbon::now();
        $writer->connection()
            ->table($this->tables->table('document_comment_anchors'))
            ->where('document_id', $event->documentId())
            ->where('generation', '<', $event->epoch)
            ->where('anchor_state', AnchorState::Attached->value)
            ->increment('version', 1, [
                'anchor_state' => $targetState->value,
                'updated_at' => $now,
            ]);

        return SinkResult::Skipped;
    }
}
