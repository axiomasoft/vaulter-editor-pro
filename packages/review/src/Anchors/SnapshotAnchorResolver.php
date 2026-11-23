<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Anchors;

use Packman\Attributes\Api;
use Vaulter\EditorPro\Review\Data\CommentAnchorData;
use Vaulter\EditorPro\Review\Data\SnapshotResolutionResult;
use Vaulter\EditorPro\Review\Enums\AnchorState;
use Vaulter\EditorPro\Review\Enums\SelectorKind;

/**
 * Canonical UTF-16 resolver and remapper for `snapshot_utf16` range comment anchors.
 *
 * Uses exact UTF-16 code units (not UTF-8 bytes) to match ProseMirror/JS offsets,
 * applies `assoc = 0` / `-1` boundary rules on edits, narrows ranges on partial deletion,
 * and transitions to `detached` (preserving `quoteText` and `sourcePosition`) when
 * the target text is deleted or cannot be unambiguously remapped.
 */
#[Api(since: '1.0')]
final readonly class SnapshotAnchorResolver
{
    private const int CONTEXT_UTF16_LEN = 24;

    public static function utf16Length(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $be = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');

        return intdiv(strlen($be), 2);
    }

    public static function utf16Substr(string $text, int $start, int $length): string
    {
        if ($length <= 0 || $text === '') {
            return '';
        }

        $be = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        $total = intdiv(strlen($be), 2);
        $from = max(0, min($start, $total));
        $end = max($from, min($from + $length, $total));
        if ($end <= $from) {
            return '';
        }

        $slice = substr($be, $from * 2, ($end - $from) * 2);

        return mb_convert_encoding($slice, 'UTF-8', 'UTF-16BE');
    }

    /**
     * Resolve or remap an anchor across a concrete snapshot document edit `(oldDoc -> newDoc)`.
     *
     * @param  array<string, mixed>  $oldDoc
     * @param  array<string, mixed>  $newDoc
     */
    public function resolveOnEdit(CommentAnchorData $anchor, array $oldDoc, array $newDoc): SnapshotResolutionResult
    {
        if ($anchor->selectorKind !== SelectorKind::SnapshotUtf16 || $anchor->anchorState !== AnchorState::Attached) {
            return new SnapshotResolutionResult($anchor->anchorState, $anchor->selectorPayload, $anchor->quoteText, false);
        }

        $oldText = $this->findBlockTextById($oldDoc, $anchor->blockId);
        $newText = $this->findBlockTextById($newDoc, $anchor->blockId);

        if ($newText === null) {
            return $this->detach($anchor);
        }

        if ($oldText === null) {
            return $this->resolveStateless($anchor, $newDoc);
        }

        $bounds = $this->extractBounds($anchor->selectorPayload);
        if ($bounds === null) {
            return $this->detach($anchor);
        }
        [$from, $to] = $bounds;

        if ($oldText === $newText) {
            return $this->sameText($anchor, $oldText, $from, $to);
        }

        [$delStart, $delEnd, $insLen] = $this->computeSingleEditSpan($oldText, $newText);
        $delta = $insLen - ($delEnd - $delStart);

        $newFrom = $this->mapStartOffset($from, $delStart, $delEnd, $insLen, $delta);
        $newTo = $this->mapEndOffset($to, $delStart, $delEnd, $delta);

        if ($newFrom >= $newTo) {
            return $this->detach($anchor);
        }

        $newLen = self::utf16Length($newText);
        if ($newTo > $newLen) {
            return $this->detach($anchor);
        }

        $newQuote = self::utf16Substr($newText, $newFrom, $newTo - $newFrom);
        if ($newQuote === '') {
            return $this->detach($anchor);
        }

        $prefixStart = max(0, $newFrom - self::CONTEXT_UTF16_LEN);
        $prefix = self::utf16Substr($newText, $prefixStart, $newFrom - $prefixStart);
        $suffix = self::utf16Substr($newText, $newTo, self::CONTEXT_UTF16_LEN);

        $updatedPayload = [
            'schema' => 1,
            'block_id' => $anchor->blockId,
            'from_utf16' => $newFrom,
            'to_utf16' => $newTo,
            'prefix' => $prefix,
            'suffix' => $suffix,
        ];

        $changed = $updatedPayload !== $anchor->selectorPayload || $newQuote !== $anchor->quoteText;

        return new SnapshotResolutionResult(
            state: AnchorState::Attached,
            selectorPayload: $updatedPayload,
            quoteText: $newQuote,
            changed: $changed,
        );
    }

    /**
     * Stateless reconciliation against a target TipTap document using `blockId`,
     * UTF-16 offsets, exact quote match, and prefix/suffix disambiguation.
     *
     * @param  array<string, mixed>  $doc
     */
    public function resolveStateless(CommentAnchorData $anchor, array $doc): SnapshotResolutionResult
    {
        if ($anchor->selectorKind !== SelectorKind::SnapshotUtf16 || $anchor->anchorState !== AnchorState::Attached) {
            return new SnapshotResolutionResult($anchor->anchorState, $anchor->selectorPayload, $anchor->quoteText, false);
        }

        $text = $this->findBlockTextById($doc, $anchor->blockId);
        if ($text === null) {
            return $this->detach($anchor);
        }

        $bounds = $this->extractBounds($anchor->selectorPayload);
        if ($bounds === null) {
            return $this->detach($anchor);
        }
        [$from, $to] = $bounds;

        $quoteLen = self::utf16Length($anchor->quoteText);
        if ($quoteLen === 0) {
            return $this->detach($anchor);
        }

        // Exact slice still matches at recorded UTF-16 offsets.
        if (self::utf16Substr($text, $from, $to - $from) === $anchor->quoteText) {
            return new SnapshotResolutionResult(AnchorState::Attached, $anchor->selectorPayload, $anchor->quoteText, false);
        }

        // Look up all occurrences of quote_text in UTF-16 code units.
        $matches = $this->findUtf16Occurrences($text, $anchor->quoteText);
        if (count($matches) === 1) {
            return $this->remapToOffset($anchor, $text, $matches[0], $quoteLen);
        }

        if (count($matches) > 1) {
            $prefix = is_string($anchor->selectorPayload['prefix'] ?? null) ? $anchor->selectorPayload['prefix'] : '';
            $suffix = is_string($anchor->selectorPayload['suffix'] ?? null) ? $anchor->selectorPayload['suffix'] : '';
            $prefixLen = self::utf16Length($prefix);
            $suffixLen = self::utf16Length($suffix);

            if ($prefixLen > 0 || $suffixLen > 0) {
                $filtered = array_values(array_filter(
                    $matches,
                    static function (int $pos) use ($text, $prefix, $prefixLen, $suffix, $suffixLen, $quoteLen): bool {
                        $prefixOk = $prefixLen === 0
                            || ($pos >= $prefixLen && self::utf16Substr($text, $pos - $prefixLen, $prefixLen) === $prefix);
                        $suffixOk = $suffixLen === 0
                            || self::utf16Substr($text, $pos + $quoteLen, $suffixLen) === $suffix;

                        return $prefixOk && $suffixOk;
                    },
                ));

                if (count($filtered) === 1) {
                    return $this->remapToOffset($anchor, $text, $filtered[0], $quoteLen);
                }
            }
        }

        // Ambiguous or not found => detach without destroying quote_text or source_position.
        return $this->detach($anchor);
    }

    /** @param array<string, mixed> $doc */
    public function findBlockTextById(array $doc, string $blockId): ?string
    {
        return $this->walkFindBlock($doc, $blockId);
    }

    /** @param array<string, mixed> $node */
    private function walkFindBlock(array $node, string $blockId): ?string
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        if (($attrs['blockId'] ?? null) === $blockId) {
            return $this->extractNodeText($node);
        }

        if (is_array($node['content'] ?? null)) {
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    /** @var array<string, mixed> $child */
                    $found = $this->walkFindBlock($child, $blockId);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $node */
    private function extractNodeText(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return is_string($node['text'] ?? null) ? $node['text'] : '';
        }
        if (($node['type'] ?? null) === 'hardBreak') {
            return "\n";
        }
        if (! is_array($node['content'] ?? null)) {
            return '';
        }

        $buffer = '';
        foreach ($node['content'] as $child) {
            if (is_array($child)) {
                /** @var array<string, mixed> $child */
                $buffer .= $this->extractNodeText($child);
            }
        }

        return $buffer;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: int}|null
     */
    private function extractBounds(array $payload): ?array
    {
        $from = $payload['from_utf16'] ?? null;
        $to = $payload['to_utf16'] ?? null;
        if (! is_int($from) || ! is_int($to) || $from < 0 || $to <= $from) {
            return null;
        }

        return [$from, $to];
    }

    /** @return array{0: int, 1: int, 2: int} [delStart, delEnd, insLen] in UTF-16 code units */
    private function computeSingleEditSpan(string $oldText, string $newText): array
    {
        $oldUnits = $this->toUtf16Units($oldText);
        $newUnits = $this->toUtf16Units($newText);
        $oldLen = count($oldUnits);
        $newLen = count($newUnits);

        $prefix = 0;
        $minLen = min($oldLen, $newLen);
        while ($prefix < $minLen && $oldUnits[$prefix] === $newUnits[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        $maxSuffix = min($oldLen - $prefix, $newLen - $prefix);
        while ($suffix < $maxSuffix && $oldUnits[$oldLen - 1 - $suffix] === $newUnits[$newLen - 1 - $suffix]) {
            $suffix++;
        }

        $delStart = $prefix;
        $delEnd = $oldLen - $suffix;
        $insLen = ($newLen - $suffix) - $prefix;

        return [$delStart, $delEnd, $insLen];
    }

    /** @return list<string> */
    private function toUtf16Units(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $be = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');

        return str_split($be, 2);
    }

    private function mapStartOffset(int $from, int $delStart, int $delEnd, int $insLen, int $delta): int
    {
        if ($delStart === $delEnd) {
            // Pure insertion: assoc = 0 moves start right when inserted exactly at `from`.
            return $from >= $delStart ? $from + $insLen : $from;
        }
        if ($from < $delStart) {
            return $from;
        }
        if ($from < $delEnd) {
            return $delStart + $insLen;
        }

        return $from + $delta;
    }

    private function mapEndOffset(int $to, int $delStart, int $delEnd, int $delta): int
    {
        if ($delStart === $delEnd) {
            // Pure insertion: assoc = -1 keeps end left when inserted exactly at `to`.
            return $to <= $delStart ? $to : $to + $delta;
        }
        if ($to <= $delStart) {
            return $to;
        }
        if ($to <= $delEnd) {
            return $delStart;
        }

        return $to + $delta;
    }

    /** @return list<int> */
    private function findUtf16Occurrences(string $haystack, string $needle): array
    {
        $hayBe = mb_convert_encoding($haystack, 'UTF-16BE', 'UTF-8');
        $neeBe = mb_convert_encoding($needle, 'UTF-16BE', 'UTF-8');
        if ($neeBe === '') {
            return [];
        }

        $offsets = [];
        $byteOffset = 0;
        $hayLen = strlen($hayBe);
        while ($byteOffset <= $hayLen) {
            $found = strpos($hayBe, $neeBe, $byteOffset);
            if ($found === false) {
                break;
            }
            if ($found % 2 === 0) {
                $offsets[] = intdiv($found, 2);
                $byteOffset = $found + 2;
            } else {
                $byteOffset = $found + 1;
            }
        }

        return $offsets;
    }

    private function sameText(CommentAnchorData $anchor, string $text, int $from, int $to): SnapshotResolutionResult
    {
        if ($to > self::utf16Length($text)) {
            return $this->detach($anchor);
        }

        return new SnapshotResolutionResult(
            state: AnchorState::Attached,
            selectorPayload: $anchor->selectorPayload,
            quoteText: $anchor->quoteText,
            changed: false,
        );
    }

    private function remapToOffset(CommentAnchorData $anchor, string $text, int $from, int $quoteLen): SnapshotResolutionResult
    {
        $to = $from + $quoteLen;
        $prefixStart = max(0, $from - self::CONTEXT_UTF16_LEN);
        $prefix = self::utf16Substr($text, $prefixStart, $from - $prefixStart);
        $suffix = self::utf16Substr($text, $to, self::CONTEXT_UTF16_LEN);

        $payload = [
            'schema' => 1,
            'block_id' => $anchor->blockId,
            'from_utf16' => $from,
            'to_utf16' => $to,
            'prefix' => $prefix,
            'suffix' => $suffix,
        ];

        return new SnapshotResolutionResult(
            state: AnchorState::Attached,
            selectorPayload: $payload,
            quoteText: $anchor->quoteText,
            changed: $payload !== $anchor->selectorPayload,
        );
    }

    private function detach(CommentAnchorData $anchor): SnapshotResolutionResult
    {
        return new SnapshotResolutionResult(
            state: AnchorState::Detached,
            selectorPayload: $anchor->selectorPayload,
            quoteText: $anchor->quoteText,
            changed: $anchor->anchorState !== AnchorState::Detached,
        );
    }
}
