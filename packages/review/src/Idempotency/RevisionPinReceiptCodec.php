<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Idempotency;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\RevisionPinData;
use Vaulter\EditorPro\Review\Enums\PinReason;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/** Encodes/decodes a `RevisionPinData` or unpin status idempotency receipt. Version 1. */
#[Internal]
final class RevisionPinReceiptCodec implements ReceiptCodec
{
    public const string KIND = 'review.revision-pin';

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(private readonly string $nodeId) {}

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
        if (is_bool($result)) {
            return ['type' => 'unpin', 'unpinned' => $result];
        }

        if (! $result instanceof RevisionPinData) {
            throw new InvalidArgumentException(sprintf('[Vaulter Review] %s expects RevisionPinData|bool, %s given.', self::KIND, get_debug_type($result)));
        }

        return [
            'type' => 'pin',
            'pin' => [
                'revision_id' => $result->revisionId,
                'document_id' => $result->documentId,
                'pin_reason' => $result->pinReason->value,
                'source_generation' => $result->sourceGeneration,
                'source_position' => $result->sourcePosition,
                'pinned_by_subject' => $result->pinnedBySubject,
                'pinned_at' => $result->pinnedAt->format(self::TIME_FORMAT),
                'expires_at' => $result->expiresAt?->format(self::TIME_FORMAT),
            ],
        ];
    }

    public function decode(array $body, int $version): RevisionPinData|bool
    {
        if ($version !== 1) {
            throw new LogicException(sprintf('[Vaulter Review] Unknown receipt version %d for %s.', $version, self::KIND));
        }

        if (($body['type'] ?? null) === 'unpin') {
            return (bool) ($body['unpinned'] ?? false);
        }

        $p = $body['pin'] ?? null;
        if (! is_array($p)) {
            throw new LogicException('[Vaulter Review] Missing pin payload in receipt.');
        }

        return new RevisionPinData(
            revisionId: $this->requireString($p, 'revision_id'),
            documentId: $this->requireString($p, 'document_id'),
            pinReason: PinReason::from($this->requireString($p, 'pin_reason')),
            sourceGeneration: $this->requireInt($p, 'source_generation'),
            sourcePosition: $this->requireInt($p, 'source_position'),
            pinnedBySubject: $this->requireString($p, 'pinned_by_subject'),
            pinnedAt: $this->requiredTime($p['pinned_at'] ?? null),
            expiresAt: $this->nullableTime($p['expires_at'] ?? null),
        );
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('node', $this->nodeId);
    }

    /** @param array<array-key, mixed> $fields */
    private function requireString(array $fields, string $key): string
    {
        $val = $fields[$key] ?? null;
        if (! is_string($val)) {
            throw new LogicException(sprintf('[Vaulter Review] Pin receipt missing string "%s".', $key));
        }

        return $val;
    }

    /** @param array<array-key, mixed> $fields */
    private function requireInt(array $fields, string $key): int
    {
        $val = $fields[$key] ?? null;
        if (! is_int($val)) {
            throw new LogicException(sprintf('[Vaulter Review] Pin receipt missing int "%s".', $key));
        }

        return $val;
    }

    private function requiredTime(mixed $raw): DateTimeImmutable
    {
        return $this->nullableTime($raw) ?? throw new LogicException('[Vaulter Review] Pin receipt missing timestamp.');
    }

    private function nullableTime(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $raw);

        return $parsed !== false ? $parsed : throw new LogicException('[Vaulter Review] Invalid timestamp in pin receipt.');
    }
}
