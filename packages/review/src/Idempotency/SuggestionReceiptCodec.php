<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Idempotency;

use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/** Captures the suggestion versions and status returned by the original write. */
#[Internal]
final readonly class SuggestionReceiptCodec implements ReceiptCodec
{
    public function __construct(private string $nodeId) {}

    public function kind(): string
    {
        return 'review.suggestion';
    }

    public function version(): int
    {
        return 1;
    }

    public function encode(mixed $result): array
    {
        $single = $result instanceof TextSuggestion;
        $items = $single ? [$result] : $result;
        if (! is_array($items)) {
            throw new LogicException('Invalid suggestion result.');
        }
        $encoded = [];
        foreach ($items as $suggestion) {
            if (! $suggestion instanceof TextSuggestion) {
                throw new LogicException('Invalid suggestion result.');
            }
            $fields = get_object_vars($suggestion);
            $fields['decidedAt'] = $suggestion->decidedAt?->format('Y-m-d\TH:i:s.uP');
            $encoded[] = $fields;
        }

        return ['single' => $single, 'items' => $encoded];
    }

    /** @return TextSuggestion|list<TextSuggestion> */
    public function decode(array $body, int $version): TextSuggestion|array
    {
        if ($version !== 1 || ! is_array($body['items'] ?? null)) {
            throw new LogicException('Invalid suggestion receipt.');
        }
        $items = [];
        foreach ($body['items'] as $item) {
            if (! is_array($item)) {
                throw new LogicException('Invalid suggestion item in receipt.');
            }
            $items[] = $this->decodeItem($item);
        }

        return ($body['single'] ?? false) === true ? ($items[0] ?? throw new LogicException('Missing suggestion receipt item.')) : $items;
    }

    /** @param array<array-key, mixed> $fields */
    private function decodeItem(array $fields): TextSuggestion
    {
        $date = $this->optionalString($fields['decidedAt'] ?? null);
        $position = $fields['committedPosition'] ?? null;

        return new TextSuggestion(
            id: $this->string($fields, 'id'),
            groupId: $this->string($fields, 'groupId'),
            proposalId: $this->optionalString($fields['proposalId'] ?? null),
            documentId: $this->string($fields, 'documentId'),
            generation: $this->integer($fields, 'generation'),
            basePosition: $this->integer($fields, 'basePosition'),
            schemaVersion: $this->integer($fields, 'schemaVersion'),
            authorSubject: $this->string($fields, 'authorSubject'),
            kind: $this->string($fields, 'kind'),
            targetBlockId: $this->string($fields, 'targetBlockId'),
            from: $this->integer($fields, 'from'),
            to: $this->integer($fields, 'to'),
            parentId: $this->optionalString($fields['parentId'] ?? null),
            conflicted: ($fields['conflicted'] ?? null) === true,
            before: $this->string($fields, 'before'),
            after: $this->string($fields, 'after'),
            state: $this->string($fields, 'state'),
            version: $this->integer($fields, 'version'),
            receiptId: $this->optionalString($fields['receiptId'] ?? null),
            decidedBySubject: $this->optionalString($fields['decidedBySubject'] ?? null),
            decidedAt: $date === null ? null : new DateTimeImmutable($date),
            targetSelector: $this->object($fields['targetSelector'] ?? null),
            payload: $this->object($fields['payload'] ?? null),
            conflictReason: $this->optionalString($fields['conflictReason'] ?? null),
            conflictWithId: $this->optionalString($fields['conflictWithId'] ?? null),
            journalCommandId: $this->optionalString($fields['journalCommandId'] ?? null),
            journalState: $this->optionalString($fields['journalState'] ?? null),
            committedPosition: is_int($position) ? $position : null,
        );
    }

    /** @param array<array-key, mixed> $fields */
    private function string(array $fields, string $name): string
    {
        return $this->optionalString($fields[$name] ?? null) ?? throw new LogicException('Missing suggestion receipt field '.$name);
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @param array<array-key, mixed> $fields */
    private function integer(array $fields, string $name): int
    {
        $value = $fields[$name] ?? null;

        return is_int($value) ? $value : throw new LogicException('Missing suggestion receipt field '.$name);
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new LogicException('Invalid suggestion receipt object.');
        }

        return array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('node', $this->nodeId);
    }
}
