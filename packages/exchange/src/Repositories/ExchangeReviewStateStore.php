<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Repositories;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use JsonException;
use Packman\Attributes\Internal;
use stdClass;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\EditorPro\Review\Data\AnchoredCommentData;
use Vaulter\EditorPro\Review\Enums\AnchorState;
use Vaulter\EditorPro\Review\Enums\CommentFilterStatus;
use Vaulter\EditorPro\Review\Enums\SelectorKind;
use Vaulter\EditorPro\Review\Operations\AnchoredCommentOperations;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Support\Identity;

/**
 * Persistence and export hydration bridge for D2 sections (`v_document_sections`),
 * D3 anchored discussions (`v_node_comments` + `v_document_comment_anchors`),
 * and D4 suggestions (`v_document_suggestions`).
 */
#[Internal]
final readonly class ExchangeReviewStateStore
{
    public function __construct(
        private ExchangeLookup $lookup,
        private Container $container,
    ) {}

    /** @return list<array<string, mixed>> */
    public function loadSections(int|string $documentId, int $generation, ?Connection $connection = null): array
    {
        $conn = $connection ?? $this->lookup->connection();
        $table = $this->lookup->table('document_sections');
        if (! $conn->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $raw = $conn->table($table)
            ->where('document_id', $documentId)
            ->where('generation', $generation)
            ->value('sections_json');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_array($item)) {
                $out[] = self::stringKeyMap($item);
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function loadDiscussions(
        DriveHandle $drive,
        string $nodeId,
        int|string $documentId,
        int $generation,
        ?Connection $connection = null,
    ): array {
        $conn = $connection ?? $this->lookup->connection();
        if (class_exists(AnchoredCommentOperations::class)) {
            try {
                /** @var list<AnchoredCommentData> $items */
                $items = $drive->extension(AnchoredCommentOperations::class)
                    ->list($nodeId, CommentFilterStatus::All)
                    ->items;

                return $this->mapAnchoredComments($items, $generation, $conn);
            } catch (\Throwable) {
                // Fall through to direct DB read if review extension is not active on this drive.
            }
        }

        $commentsTable = $this->lookup->table('node_comments');
        $anchorsTable = $this->lookup->table('document_comment_anchors');
        $hasAnchors = $conn->getSchemaBuilder()->hasTable($anchorsTable);

        $rows = $conn->table($commentsTable)
            ->where('node_id', $nodeId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'author_type', 'author_id', 'body', 'anchor', 'resolved_at', 'created_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var array<string, stdClass> $anchorsByComment */
        $anchorsByComment = [];
        if ($hasAnchors) {
            $anchorRows = $conn->table($anchorsTable)
                ->where('document_id', $documentId)
                ->where('generation', $generation)
                ->get(['comment_id', 'block_id', 'selector_payload', 'quote_text', 'anchor_state', 'source_position']);
            foreach ($anchorRows as $aRow) {
                $anchorsByComment[Identity::stringify($aRow->comment_id)] = $aRow;
            }
        }

        $roots = [];
        $repliesByRoot = [];
        foreach ($rows as $row) {
            $id = Identity::stringify($row->id);
            $parentId = $row->parent_id !== null ? Identity::stringify($row->parent_id) : null;
            if ($parentId === null) {
                $roots[$id] = $row;
            } else {
                $repliesByRoot[$parentId][] = $row;
            }
        }

        $discussions = [];
        foreach ($roots as $rootId => $root) {
            $anchorRow = $anchorsByComment[$rootId] ?? null;
            if ($anchorRow !== null && Identity::stringify($anchorRow->anchor_state) === AnchorState::Historical->value) {
                continue;
            }
            $payload = $anchorRow !== null ? self::decodeJsonMap($anchorRow->selector_payload ?? null) : [];
            $comments = [$this->formatCommentRow($root)];
            foreach ($repliesByRoot[$rootId] ?? [] as $reply) {
                $comments[] = $this->formatCommentRow($reply);
            }

            $discussions[] = [
                'id' => $rootId,
                'anchorState' => $anchorRow !== null ? Identity::stringify($anchorRow->anchor_state) : 'detached',
                'quoteText' => $anchorRow !== null ? Identity::stringify($anchorRow->quote_text ?? '') : null,
                'blockId' => $anchorRow !== null ? Identity::stringify($anchorRow->block_id ?? '') : null,
                'sourcePosition' => is_int($payload['from_utf16'] ?? null) ? $payload['from_utf16'] : null,
                'generation' => $generation,
                'resolved' => $root->resolved_at !== null,
                'comments' => $comments,
            ];
        }

        return $discussions;
    }

    /** @return list<array<string, mixed>> */
    public function loadOpenSuggestions(int|string $documentId, int $generation, ?Connection $connection = null): array
    {
        $conn = $connection ?? $this->lookup->connection();
        $table = $this->lookup->table('document_suggestions');
        if (! $conn->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $rows = $conn->table($table)
            ->where('document_id', $documentId)
            ->where('generation', $generation)
            ->where('state', 'open')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'group_id', 'author_subject', 'kind', 'target_block_id', 'target_selector', 'payload', 'provenance_json', 'created_at']);

        $out = [];
        foreach ($rows as $row) {
            $selector = self::decodeJsonMap($row->target_selector ?? null);
            $payload = self::decodeJsonMap($row->payload ?? null);
            $prov = self::decodeJsonMap($row->provenance_json ?? null);
            $author = is_string($prov['imported_author'] ?? null) && $prov['imported_author'] !== ''
                ? $prov['imported_author']
                : Identity::stringify($row->author_subject);
            $createdAt = is_string($prov['imported_created_at'] ?? null) && $prov['imported_created_at'] !== ''
                ? $prov['imported_created_at']
                : Identity::stringify($row->created_at);
            $groupId = is_string($prov['ooxml_group_id'] ?? null) && $prov['ooxml_group_id'] !== ''
                ? $prov['ooxml_group_id']
                : Identity::stringify($row->group_id);

            $beforeRaw = $payload['before'] ?? '';
            $afterRaw = $payload['after'] ?? '';

            $out[] = [
                'id' => Identity::stringify($row->id),
                'kind' => Identity::stringify($row->kind),
                'group_id' => $groupId,
                'author' => $author,
                'created_at' => $createdAt,
                'target_block_id' => Identity::stringify($row->target_block_id),
                'from_utf16' => is_int($selector['from_utf16'] ?? null) ? $selector['from_utf16'] : 0,
                'to_utf16' => is_int($selector['to_utf16'] ?? null) ? $selector['to_utf16'] : 0,
                'before' => is_string($beforeRaw) ? $beforeRaw : '',
                'after' => is_string($afterRaw) ? $afterRaw : '',
                'payload' => $payload,
            ];
        }

        return $out;
    }

    /**
     * Bind extracted D3 discussion & D4 revision block indices onto assigned `blockId` values.
     *
     * @param  array<string, mixed>  $normalizedDoc
     * @param  list<array<string, mixed>>  $discussions
     * @param  list<array<string, mixed>>  $revisions
     */
    public function resolvePreviewBlockBindings(array $normalizedDoc, array &$discussions, array &$revisions): void
    {
        $blockIds = [];
        $this->collectTextblockIds($normalizedDoc, $blockIds);

        foreach ($discussions as &$d) {
            $idx = $d['blockIndex'] ?? null;
            if (is_int($idx) && isset($blockIds[$idx])) {
                $d['blockId'] = $blockIds[$idx];
            } else {
                $d['blockId'] = null;
                $d['anchorState'] = 'detached';
            }
        }
        unset($d);

        foreach ($revisions as &$r) {
            $idx = $r['block_index'] ?? null;
            if (is_int($idx) && isset($blockIds[$idx])) {
                $r['target_block_id'] = $blockIds[$idx];
            }
            $secondIdx = $r['second_block_index'] ?? null;
            if (is_int($secondIdx) && isset($blockIds[$secondIdx])) {
                $r['second_block_id'] = $blockIds[$secondIdx];
            }
        }
        unset($r);
    }

    /**
     * Atomically persist D2 sections, D3 discussions/anchors, and D4 suggestions on `$conn`.
     *
     * @param  array<string, mixed>  $previewPayload
     * @param  array<string, mixed>  $options
     */
    public function persistImportedStateOnConnection(
        Connection $conn,
        DriveContext $context,
        int|string $documentId,
        string $nodeId,
        string $jobId,
        int $generation,
        int $position,
        int $schemaVersion,
        array $previewPayload,
        array $options = [],
    ): void {
        $tenantId = $this->lookup->normalizeTenantId($context->operation->tenant->id);
        $now = $this->lookup->dbNow($conn);

        /** @var list<array<string, mixed>> $sections */
        $sections = self::listOfStringMaps($previewPayload['sections'] ?? null);
        $this->upsertSections($conn, $tenantId, $documentId, $generation, $position, $schemaVersion, $sections, $now);

        /** @var list<array<string, mixed>> $discussions */
        $discussions = self::listOfStringMaps($previewPayload['discussions'] ?? null);
        if ($discussions !== []) {
            $this->persistDiscussions($conn, $context, $tenantId, $documentId, $nodeId, $generation, $position, $schemaVersion, $previewPayload, $discussions, $now);
        }

        /** @var list<array<string, mixed>> $revisions */
        $revisions = self::listOfStringMaps($previewPayload['revisions'] ?? null);
        if ($revisions !== []) {
            $this->persistSuggestions($conn, $context, $tenantId, $documentId, $jobId, $generation, $position, $schemaVersion, $revisions, $options, $now);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function upsertSections(
        Connection $conn,
        int|string $tenantId,
        int|string $documentId,
        int $generation,
        int $position,
        int $schemaVersion,
        array $sections,
        string $now,
    ): void {
        $table = $this->lookup->table('document_sections');
        if (! $conn->getSchemaBuilder()->hasTable($table)) {
            return;
        }

        $encoded = json_encode($sections, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $existingId = $conn->table($table)
            ->where('document_id', $documentId)
            ->where('generation', $generation)
            ->value('id');

        if ($existingId !== null) {
            $conn->table($table)
                ->where('id', $existingId)
                ->update([
                    'source_position' => $position,
                    'schema_version' => $schemaVersion,
                    'sections_json' => $encoded,
                    'updated_at' => $now,
                ]);

            return;
        }

        $conn->table($table)->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'document_id' => $documentId,
            'generation' => $generation,
            'source_position' => $position,
            'schema_version' => $schemaVersion,
            'sections_json' => $encoded,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $previewPayload
     * @param  list<array<string, mixed>>  $discussions
     */
    private function persistDiscussions(
        Connection $conn,
        DriveContext $context,
        int|string $tenantId,
        int|string $documentId,
        string $nodeId,
        int $generation,
        int $position,
        int $schemaVersion,
        array $previewPayload,
        array $discussions,
        string $now,
    ): void {
        $commentsTable = $this->lookup->table('node_comments');
        $anchorsTable = $this->lookup->table('document_comment_anchors');
        if (! $conn->getSchemaBuilder()->hasTable($anchorsTable)) {
            return;
        }

        $actorRef = $context->actor->ref();
        if ($context->actor->isSystem() || $actorRef->type === null || $actorRef->id === null) {
            throw MissingActorException::required();
        }

        $idStrategy = $this->container->make(IdsOptions::class)->strategy;
        $fallbackBlockIds = [];
        if (is_array($previewPayload['document'] ?? null)) {
            $this->collectTextblockIds(self::stringKeyMap($previewPayload['document']), $fallbackBlockIds);
        }
        $defaultBlockId = $fallbackBlockIds[0] ?? 'imported-doc';

        foreach ($discussions as $discussion) {
            $rawComments = self::listOfStringMaps($discussion['comments'] ?? null);
            if ($rawComments === []) {
                continue;
            }

            $resolved = ($discussion['resolved'] ?? false) === true;
            $anchorState = Identity::stringify($discussion['anchorState'] ?? 'attached');
            $blockId = is_string($discussion['blockId'] ?? null) && $discussion['blockId'] !== ''
                ? $discussion['blockId']
                : $defaultBlockId;
            if (! is_string($discussion['blockId'] ?? null) || $discussion['blockId'] === '') {
                $anchorState = AnchorState::Detached->value;
            }

            $from = is_int($discussion['fromUtf16'] ?? null) ? max(0, $discussion['fromUtf16']) : 0;
            $to = is_int($discussion['toUtf16'] ?? null) ? $discussion['toUtf16'] : $from;
            if ($to <= $from) {
                $to = $from + 1;
                $anchorState = AnchorState::Detached->value;
            }

            $quoteText = is_string($discussion['quoteText'] ?? null) ? $discussion['quoteText'] : '';
            $prefix = is_string($discussion['prefix'] ?? null) ? $discussion['prefix'] : '';
            $suffix = is_string($discussion['suffix'] ?? null) ? $discussion['suffix'] : '';

            $rootComment = $rawComments[0];
            $rootId = $this->insertNodeComment(
                conn: $conn,
                table: $commentsTable,
                idStrategy: $idStrategy,
                tenantId: $tenantId,
                nodeId: $nodeId,
                parentId: null,
                authorType: $actorRef->type,
                authorId: $actorRef->id,
                body: is_string($rootComment['body'] ?? null) ? $rootComment['body'] : '',
                anchorMeta: [
                    'kind' => SelectorKind::SnapshotUtf16->value,
                    'block_id' => $blockId,
                    'generation' => $generation,
                    'imported_author' => is_string($rootComment['author'] ?? null) ? $rootComment['author'] : null,
                    'imported_created_at' => is_string($rootComment['createdAt'] ?? null) ? $rootComment['createdAt'] : null,
                ],
                resolvedAt: $resolved ? $now : null,
                resolvedBy: $resolved ? $actorRef->id : null,
                now: $now,
            );

            $anchorId = self::mintId($idStrategy);
            $anchorRow = [
                'tenant_id' => $tenantId,
                'comment_id' => $rootId,
                'document_id' => $documentId,
                'generation' => $generation,
                'source_position' => $position,
                'schema_version' => $schemaVersion,
                'block_id' => substr($blockId, 0, 255),
                'selector_kind' => SelectorKind::SnapshotUtf16->value,
                'selector_payload' => json_encode([
                    'schema' => 1,
                    'block_id' => substr($blockId, 0, 255),
                    'from_utf16' => $from,
                    'to_utf16' => $to,
                    'prefix' => $prefix,
                    'suffix' => $suffix,
                ], JSON_THROW_ON_ERROR),
                'quote_text' => $quoteText,
                'anchor_state' => in_array($anchorState, ['attached', 'detached', 'historical'], true)
                    ? $anchorState
                    : AnchorState::Detached->value,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($anchorId !== null) {
                $anchorRow['id'] = $anchorId;
            }
            $conn->table($anchorsTable)->insert($anchorRow);

            for ($i = 1, $count = count($rawComments); $i < $count; $i++) {
                $reply = $rawComments[$i];
                $this->insertNodeComment(
                    conn: $conn,
                    table: $commentsTable,
                    idStrategy: $idStrategy,
                    tenantId: $tenantId,
                    nodeId: $nodeId,
                    parentId: $rootId,
                    authorType: $actorRef->type,
                    authorId: $actorRef->id,
                    body: is_string($reply['body'] ?? null) ? $reply['body'] : '',
                    anchorMeta: [
                        'imported_author' => is_string($reply['author'] ?? null) ? $reply['author'] : null,
                        'imported_created_at' => is_string($reply['createdAt'] ?? null) ? $reply['createdAt'] : null,
                    ],
                    resolvedAt: null,
                    resolvedBy: null,
                    now: $now,
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $revisions
     * @param  array<string, mixed>  $options
     */
    private function persistSuggestions(
        Connection $conn,
        DriveContext $context,
        int|string $tenantId,
        int|string $documentId,
        string $jobId,
        int $generation,
        int $position,
        int $schemaVersion,
        array $revisions,
        array $options,
        string $now,
    ): void {
        $table = $this->lookup->table('document_suggestions');
        if (! $conn->getSchemaBuilder()->hasTable($table)) {
            return;
        }

        $actorSubject = ExchangeLookup::effectiveSubject($context);
        $authorMap = is_array($options['author_map'] ?? null) ? self::stringKeyMap($options['author_map']) : [];
        $authorPolicy = is_string($options['imported_author_policy'] ?? null) ? $options['imported_author_policy'] : 'prefix';

        foreach ($revisions as $index => $rev) {
            $kind = Identity::stringify($rev['kind'] ?? '');
            if (! in_array($kind, ['insert', 'delete', 'replace', 'mark_change', 'block_join'], true)) {
                continue;
            }
            $targetBlockId = is_string($rev['target_block_id'] ?? null) ? $rev['target_block_id'] : '';
            if ($targetBlockId === '') {
                continue;
            }

            $rawAuthor = is_string($rev['author'] ?? null) && trim($rev['author']) !== ''
                ? trim($rev['author'])
                : 'Unknown Author';
            $authorSubject = $this->mapAuthorSubject($rawAuthor, $actorSubject, $authorMap, $authorPolicy);

            $rawGroup = is_string($rev['group_id'] ?? null) && $rev['group_id'] !== '' ? $rev['group_id'] : null;
            $groupId = self::deterministicUuid($rawGroup !== null
                ? 'exchange-group:'.$jobId.':'.$rawGroup
                : 'exchange-single:'.$jobId.':'.$index);
            $suggestionId = self::deterministicUuid('exchange-suggestion:'.$jobId.':'.$index);

            $from = is_int($rev['from_utf16'] ?? null) ? max(0, $rev['from_utf16']) : 0;
            $to = is_int($rev['to_utf16'] ?? null) ? max($from, $rev['to_utf16']) : $from;
            $before = is_string($rev['before'] ?? null) ? $rev['before'] : '';
            $after = is_string($rev['after'] ?? null) ? $rev['after'] : '';

            $extraPayload = is_array($rev['payload'] ?? null) ? self::stringKeyMap($rev['payload']) : [];
            $payload = match ($kind) {
                'mark_change' => $extraPayload + ['profile' => 'mark_change', 'before' => $before, 'after' => $after],
                'block_join' => $extraPayload + [
                    'profile' => 'block_join',
                    'second_block_id' => is_string($rev['second_block_id'] ?? null) ? $rev['second_block_id'] : '',
                    'before' => '',
                    'after' => 'join',
                ],
                default => ['before' => $before, 'after' => $after],
            };

            $conn->table($table)->insert([
                'id' => $suggestionId,
                'tenant_id' => $tenantId,
                'document_id' => $documentId,
                'group_id' => $groupId,
                'proposal_id' => null,
                'generation' => $generation,
                'base_position' => $position,
                'schema_version' => $schemaVersion,
                'author_subject' => $authorSubject,
                'target_block_id' => substr($targetBlockId, 0, 255),
                'decided_by_subject' => null,
                'provenance_json' => json_encode([
                    'schema' => 1,
                    'exchange_job_id' => $jobId,
                    'imported_author' => $rawAuthor,
                    'imported_created_at' => is_string($rev['created_at'] ?? null) ? $rev['created_at'] : null,
                    'ooxml_id' => is_string($rev['id'] ?? null) ? $rev['id'] : null,
                    'ooxml_group_id' => $rawGroup,
                ], JSON_THROW_ON_ERROR),
                'kind' => $kind,
                'target_selector' => json_encode([
                    'schema' => 1,
                    'from_utf16' => $from,
                    'to_utf16' => $to,
                    'parentId' => null,
                    'conflicted' => false,
                ], JSON_THROW_ON_ERROR),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'state' => 'open',
                'decided_at' => null,
                'receipt_id' => null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $anchorMeta
     */
    private function insertNodeComment(
        Connection $conn,
        string $table,
        string $idStrategy,
        int|string $tenantId,
        string $nodeId,
        int|string|null $parentId,
        string $authorType,
        int|string $authorId,
        string $body,
        array $anchorMeta,
        ?string $resolvedAt,
        int|string|null $resolvedBy,
        string $now,
    ): int|string {
        $filteredAnchor = array_filter($anchorMeta, static fn (mixed $v): bool => $v !== null);
        $newId = self::mintId($idStrategy);
        $row = [
            'tenant_id' => $tenantId,
            'node_id' => $nodeId,
            'parent_id' => $parentId,
            'author_type' => $authorType,
            'author_id' => $authorId,
            'body' => $body,
            'anchor' => $filteredAnchor !== [] ? json_encode($filteredAnchor, JSON_THROW_ON_ERROR) : null,
            'resolved_at' => $resolvedAt,
            'resolved_by' => $resolvedBy,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($newId === null) {
            return $conn->table($table)->insertGetId($row);
        }

        $row['id'] = $newId;
        $conn->table($table)->insert($row);

        return $newId;
    }

    /**
     * @param  list<AnchoredCommentData>  $items
     * @return list<array<string, mixed>>
     */
    private function mapAnchoredComments(array $items, int $generation, Connection $conn): array
    {
        $ids = array_map(static fn (AnchoredCommentData $item): string => $item->comment->id, $items);
        $rawAnchors = [];
        if ($ids !== []) {
            $rows = $conn->table($this->lookup->table('node_comments'))
                ->whereIn('id', $ids)
                ->get(['id', 'anchor']);
            foreach ($rows as $row) {
                $rawAnchors[Identity::stringify($row->id)] = self::decodeJsonMap($row->anchor ?? null);
            }
        }

        $roots = [];
        $repliesByRoot = [];
        foreach ($items as $item) {
            if ($item->comment->parentId === null) {
                $roots[$item->comment->id] = $item;
            } else {
                $repliesByRoot[$item->comment->parentId][] = $item;
            }
        }

        $out = [];
        foreach ($roots as $rootId => $root) {
            $anchor = $root->anchor;
            if ($anchor !== null && ($anchor->generation !== $generation || $anchor->anchorState === AnchorState::Historical)) {
                continue;
            }
            $formatEntry = static function (AnchoredCommentData $entry) use ($rawAnchors): array {
                $meta = $rawAnchors[$entry->comment->id] ?? [];
                $author = is_string($meta['imported_author'] ?? null) && $meta['imported_author'] !== ''
                    ? $meta['imported_author']
                    : $entry->comment->authorType.'#'.$entry->comment->authorId;
                $createdAt = is_string($meta['imported_created_at'] ?? null) && $meta['imported_created_at'] !== ''
                    ? $meta['imported_created_at']
                    : $entry->comment->createdAt->format('c');

                return [
                    'id' => $entry->comment->id,
                    'parentId' => $entry->comment->parentId,
                    'author' => $author,
                    'createdAt' => $createdAt,
                    'body' => $entry->comment->body,
                ];
            };

            $comments = [$formatEntry($root)];
            foreach ($repliesByRoot[$rootId] ?? [] as $reply) {
                $comments[] = $formatEntry($reply);
            }

            $out[] = [
                'id' => $rootId,
                'anchorState' => $anchor !== null ? $anchor->anchorState->value : 'detached',
                'quoteText' => $anchor?->quoteText,
                'blockId' => $anchor?->blockId,
                'sourcePosition' => is_int($anchor?->selectorPayload['from_utf16'] ?? null) ? $anchor->selectorPayload['from_utf16'] : null,
                'generation' => $generation,
                'resolved' => $root->isResolved(),
                'comments' => $comments,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function formatCommentRow(stdClass $row): array
    {
        $meta = self::decodeJsonMap($row->anchor ?? null);
        $author = is_string($meta['imported_author'] ?? null) && $meta['imported_author'] !== ''
            ? $meta['imported_author']
            : Identity::stringify($row->author_type).'#'.Identity::stringify($row->author_id);
        $createdAt = is_string($meta['imported_created_at'] ?? null) && $meta['imported_created_at'] !== ''
            ? $meta['imported_created_at']
            : Identity::stringify($row->created_at);

        return [
            'id' => Identity::stringify($row->id),
            'parentId' => $row->parent_id !== null ? Identity::stringify($row->parent_id) : null,
            'author' => $author,
            'createdAt' => $createdAt,
            'body' => Identity::stringify($row->body ?? ''),
        ];
    }

    /** @param array<string, mixed> $authorMap */
    private function mapAuthorSubject(string $rawAuthor, string $actorSubject, array $authorMap, string $policy): string
    {
        $mapped = $authorMap[$rawAuthor] ?? null;
        if (is_string($mapped) && trim($mapped) !== '') {
            return mb_strcut(trim($mapped), 0, 255, 'UTF-8');
        }
        if ($policy === 'actor') {
            return $actorSubject;
        }

        return mb_strcut('docx:'.$rawAuthor, 0, 255, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $blockIds
     */
    private function collectTextblockIds(array $node, array &$blockIds): void
    {
        $type = $node['type'] ?? null;
        if (in_array($type, ['paragraph', 'heading', 'codeBlock'], true)) {
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $id = is_string($attrs['blockId'] ?? null) ? $attrs['blockId'] : '';
            $blockIds[] = $id;

            return;
        }
        if (is_array($node['content'] ?? null)) {
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    $this->collectTextblockIds(self::stringKeyMap($child), $blockIds);
                }
            }
        }
    }

    private static function mintId(string $strategy): ?string
    {
        return match ($strategy) {
            'uuid7' => (string) Str::uuid7(),
            'ulid' => strtolower((string) Str::ulid()),
            default => null,
        };
    }

    private static function deterministicUuid(string $seed): string
    {
        $hash = hash('sha256', $seed);
        $b = substr($hash, 0, 32);
        $timeHi = (hexdec(substr($b, 12, 4)) & 0x0FFF) | 0x4000;
        $clockSeq = (hexdec(substr($b, 16, 4)) & 0x3FFF) | 0x8000;

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($b, 0, 8),
            substr($b, 8, 4),
            $timeHi,
            $clockSeq,
            substr($b, 20, 12),
        );
    }

    /** @return array<string, mixed> */
    private static function decodeJsonMap(mixed $raw): array
    {
        if (is_array($raw)) {
            return self::stringKeyMap($raw);
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? self::stringKeyMap($decoded) : [];
        } catch (JsonException) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private static function listOfStringMaps(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $out[] = self::stringKeyMap($item);
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function stringKeyMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
