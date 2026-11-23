<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Suggestions;

use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Anchors\SnapshotAnchorResolver;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;

/** Apply a text edit at exact UTF-16 boundaries without rebuilding unrelated nodes. */
#[Internal]
final readonly class SnapshotTextEdit
{
    public function __construct(
        public string $blockId,
        public int $from,
        public int $to,
        public string $before,
        public string $after,
    ) {}

    /** @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public function apply(array $doc): array
    {
        $updated = $this->walk($doc);
        if ($updated === null) {
            throw new SuggestionConflictException('The target text block is unavailable.');
        }

        return $updated;
    }

    /** @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function walk(array $node): ?array
    {
        $content = $node['content'] ?? [];
        $attrs = $node['attrs'] ?? null;
        if (is_array($attrs) && ($attrs['blockId'] ?? null) === $this->blockId
            && in_array($node['type'] ?? null, ['paragraph', 'heading', 'codeBlock'], true)) {
            if (! is_array($content) || ! array_is_list($content)) {
                throw new SuggestionConflictException('Invalid target block.');
            }
            /** @var list<array<string, mixed>> $content */
            $node['content'] = $this->replaceText($content);

            return $node;
        }
        if (is_array($content)) {
            foreach ($content as $key => $child) {
                if (is_array($child)) {
                    /** @var array<string, mixed> $child */
                    $found = $this->walk($child);
                    if ($found !== null) {
                        $content[$key] = $found;
                        $node['content'] = $content;

                        return $node;
                    }
                }
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $content
     * @return list<array<string, mixed>>
     */
    private function replaceText(array $content): array
    {
        $left = $right = [];
        $quote = '';
        $position = 0;
        $marks = null;
        foreach ($content as $inline) {
            $text = ($inline['type'] ?? null) === 'text' && is_string($inline['text'] ?? null) ? $inline['text'] : null;
            $end = $position + ($text === null ? 1 : SnapshotAnchorResolver::utf16Length($text));
            if ($end <= $this->from) {
                $left[] = $inline;
            } elseif ($position >= $this->to) {
                $right[] = $inline;
            } elseif ($text === null) {
                throw new SuggestionConflictException('A text edit cannot replace an inline node.');
            } else {
                $startOffset = max(0, $this->from - $position);
                $endOffset = min($end, $this->to) - $position;
                $prefix = self::slice($text, 0, $startOffset);
                $quote .= self::slice($text, $startOffset, $endOffset);
                $suffix = self::slice($text, $endOffset, $end - $position);
                if ($prefix !== '') {
                    $left[] = [...$inline, 'text' => $prefix];
                }
                if ($suffix !== '') {
                    $right[] = [...$inline, 'text' => $suffix];
                }
                if ($startOffset < $endOffset) {
                    $marks ??= $inline['marks'] ?? [];
                }
            }
            $position = $end;
        }
        if ($this->to > $position || $quote !== $this->before) {
            throw new SuggestionConflictException('The target text has changed.');
        }
        if ($this->after !== '') {
            $leftMarks = $left[array_key_last($left)]['marks'] ?? [];
            $rightMarks = $right[0]['marks'] ?? [];
            $marks ??= $leftMarks === $rightMarks ? $leftMarks : [];
            $left[] = ['type' => 'text', 'text' => $this->after] + ($marks === [] ? [] : ['marks' => $marks]);
        }

        return [...$left, ...$right];
    }

    /** Slice at verified Unicode scalar boundaries. */
    public static function slice(string $text, int $from, int $to): string
    {
        $encoded = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        $range = substr($encoded, $from * 2, ($to - $from) * 2);
        if ($to * 2 > strlen($encoded) || ! mb_check_encoding($range, 'UTF-16BE')
            || ! mb_check_encoding(substr($encoded, 0, $from * 2), 'UTF-16BE')) {
            throw new SuggestionConflictException('The target splits a Unicode character or is outside its text.');
        }

        return mb_convert_encoding($range, 'UTF-8', 'UTF-16BE');
    }
}
