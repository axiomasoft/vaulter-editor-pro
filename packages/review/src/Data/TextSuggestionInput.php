<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Vaulter\Documents\Data\Documents\DocumentMutationState;

/** One text edit. Offsets are UTF-16 units in the block or in the parent's inserted text. */
#[Api(since: '1.0')]
final readonly class TextSuggestionInput
{
    public string $kind;

    public function __construct(
        public string $id,
        public string $groupId,
        public int $generation,
        public int $basePosition,
        public int $schemaVersion,
        public string $targetBlockId,
        public int $from,
        public int $to,
        public string $before,
        public string $after,
        public ?string $proposalId = null,
        public ?string $parentId = null,
    ) {
        foreach ([$id, $groupId, $proposalId, $parentId] as $uuid) {
            if ($uuid !== null && (! Str::isUuid($uuid) || $uuid !== strtolower($uuid))) {
                throw new InvalidArgumentException('Suggestion identifiers must be lowercase UUIDs.');
            }
        }
        if ($generation < 0 || $generation > DocumentMutationState::MAX_WIRE_COUNTER
            || $basePosition < 0 || $basePosition > DocumentMutationState::MAX_WIRE_COUNTER
            || $schemaVersion < 1 || $from < 0 || $to < $from || $to > DocumentMutationState::MAX_WIRE_COUNTER
            || strlen($targetBlockId) < 1 || strlen($targetBlockId) > 255
            || ! mb_check_encoding([$targetBlockId, $before, $after], 'UTF-8')
            || str_contains($targetBlockId.$before.$after, "\0")
            || $before === $after || ($from === $to) !== ($before === '')
            || ($parentId !== null && ($parentId === $id || $proposalId !== null))) {
            throw new InvalidArgumentException('Invalid text suggestion.');
        }

        $this->kind = $before === '' ? 'insert' : ($after === '' ? 'delete' : 'replace');
    }
}
