<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Idempotency;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\EditorPro\Review\Data\AnchoredCommentData;
use Vaulter\EditorPro\Review\Data\CommentAnchorData;
use Vaulter\EditorPro\Review\Enums\AnchorState;
use Vaulter\EditorPro\Review\Enums\SelectorKind;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/** Encodes/decodes an `AnchoredCommentData` idempotency receipt. Version 1. */
#[Internal]
final class AnchoredCommentReceiptCodec implements ReceiptCodec
{
    public const string KIND = 'review.anchored-comment';

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function kind(): string
    {
        return self::KIND;
    }

    public function version(): int
    {
        return 1;
    }

    public function encode(mixed $result): array
    {
        if (! $result instanceof AnchoredCommentData) {
            throw new InvalidArgumentException(sprintf('[Vaulter Review] %s expects AnchoredCommentData, %s given.', self::KIND, get_debug_type($result)));
        }

        $c = $result->comment;
        $a = $result->anchor;

        return [
            'etag' => $result->etag,
            'edited_at' => $result->editedAt?->format(self::TIME_FORMAT),
            'archived_at' => $result->archivedAt?->format(self::TIME_FORMAT),
            'archived_by' => $result->archivedBy,
            'comment' => [
                'id' => $c->id,
                'node_id' => $c->nodeId,
                'parent_id' => $c->parentId,
                'author_type' => $c->authorType,
                'author_id' => $c->authorId,
                'body' => $c->body,
                'anchor' => $c->anchor,
                'resolved_at' => $c->resolvedAt?->format(self::TIME_FORMAT),
                'resolved_by' => $c->resolvedBy,
                'created_at' => $c->createdAt->format(self::TIME_FORMAT),
                'version' => $c->version,
            ],
            'anchor' => $a === null ? null : [
                'id' => $a->id,
                'comment_id' => $a->commentId,
                'document_id' => $a->documentId,
                'generation' => $a->generation,
                'source_position' => $a->sourcePosition,
                'schema_version' => $a->schemaVersion,
                'block_id' => $a->blockId,
                'selector_kind' => $a->selectorKind->value,
                'selector_payload' => $a->selectorPayload,
                'quote_text' => $a->quoteText,
                'anchor_state' => $a->anchorState->value,
                'version' => $a->version,
                'etag' => $a->etag,
                'created_at' => $a->createdAt->format(self::TIME_FORMAT),
                'updated_at' => $a->updatedAt->format(self::TIME_FORMAT),
            ],
        ];
    }

    public function decode(array $body, int $version): AnchoredCommentData
    {
        if ($version !== 1) {
            throw new LogicException(sprintf('[Vaulter Review] Unknown receipt version %d for %s.', $version, self::KIND));
        }

        $c = $body['comment'] ?? null;
        if (! is_array($c)) {
            throw new LogicException('[Vaulter Review] Missing comment payload in receipt.');
        }

        /** @var array<string, mixed>|null $legacyAnchor */
        $legacyAnchor = is_array($c['anchor'] ?? null) ? $c['anchor'] : null;

        $comment = new CommentSnapshot(
            id: $this->requireString($c, 'id'),
            nodeId: $this->requireString($c, 'node_id'),
            parentId: is_string($c['parent_id'] ?? null) ? $c['parent_id'] : null,
            authorType: $this->requireString($c, 'author_type'),
            authorId: $this->requireString($c, 'author_id'),
            body: $this->requireString($c, 'body'),
            anchor: $legacyAnchor,
            resolvedAt: $this->nullableTime($c['resolved_at'] ?? null),
            resolvedBy: is_string($c['resolved_by'] ?? null) ? $c['resolved_by'] : null,
            createdAt: $this->requiredTime($c['created_at'] ?? null),
            version: $this->requireInt($c, 'version'),
        );

        $anchorData = null;
        $a = $body['anchor'] ?? null;
        if (is_array($a)) {
            /** @var array<string, mixed> $selectorPayload */
            $selectorPayload = is_array($a['selector_payload'] ?? null) ? $a['selector_payload'] : [];

            $anchorData = new CommentAnchorData(
                id: $this->requireString($a, 'id'),
                commentId: $this->requireString($a, 'comment_id'),
                documentId: $this->requireString($a, 'document_id'),
                generation: $this->requireInt($a, 'generation'),
                sourcePosition: $this->requireInt($a, 'source_position'),
                schemaVersion: $this->requireInt($a, 'schema_version'),
                blockId: $this->requireString($a, 'block_id'),
                selectorKind: SelectorKind::from($this->requireString($a, 'selector_kind')),
                selectorPayload: $selectorPayload,
                quoteText: $this->requireString($a, 'quote_text'),
                anchorState: AnchorState::from($this->requireString($a, 'anchor_state')),
                version: $this->requireInt($a, 'version'),
                etag: $this->requireString($a, 'etag'),
                createdAt: $this->requiredTime($a['created_at'] ?? null),
                updatedAt: $this->requiredTime($a['updated_at'] ?? null),
            );
        }

        return new AnchoredCommentData(
            comment: $comment,
            anchor: $anchorData,
            etag: $this->requireString($body, 'etag'),
            editedAt: $this->nullableTime($body['edited_at'] ?? null),
            archivedAt: $this->nullableTime($body['archived_at'] ?? null),
            archivedBy: is_string($body['archived_by'] ?? null) ? $body['archived_by'] : null,
        );
    }

    public function resource(mixed $result): ResourceRef
    {
        if (! $result instanceof AnchoredCommentData) {
            throw new InvalidArgumentException(sprintf('[Vaulter Review] %s expects AnchoredCommentData, %s given.', self::KIND, get_debug_type($result)));
        }

        return new ResourceRef('node', $result->comment->nodeId);
    }

    /** @param array<array-key, mixed> $fields */
    private function requireString(array $fields, string $key): string
    {
        $val = $fields[$key] ?? null;
        if (! is_string($val)) {
            throw new LogicException(sprintf('[Vaulter Review] Receipt missing string "%s".', $key));
        }

        return $val;
    }

    /** @param array<array-key, mixed> $fields */
    private function requireInt(array $fields, string $key): int
    {
        $val = $fields[$key] ?? null;
        if (! is_int($val)) {
            throw new LogicException(sprintf('[Vaulter Review] Receipt missing int "%s".', $key));
        }

        return $val;
    }

    private function requiredTime(mixed $raw): DateTimeImmutable
    {
        return $this->nullableTime($raw) ?? throw new LogicException('[Vaulter Review] Receipt missing valid timestamp.');
    }

    private function nullableTime(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $raw);

        return $parsed !== false ? $parsed : throw new LogicException('[Vaulter Review] Invalid timestamp in receipt.');
    }
}
