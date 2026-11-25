<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Anchors;

use Packman\Attributes\Internal;

/**
 * Plain text of every `attrs.blockId` block of one TipTap document, indexed by one walk.
 *
 * Reconciling the anchors of a snapshot write looks up one block per anchor in the previous
 * and the next document; walking the tree for each lookup made a write cost
 * O(anchors x document). The index keeps the first block in document order for each id
 * (the same block a depth-first search finds) and extracts its text on first use.
 */
#[Internal]
final class SnapshotBlockTexts
{
    /** @var array<string, string> */
    private array $texts = [];

    /** @param  array<string, array<string, mixed>>  $blocks */
    private function __construct(private array $blocks) {}

    /** @param  array<string, mixed>  $doc */
    public static function of(array $doc): self
    {
        $blocks = [];
        self::index($doc, $blocks);

        return new self($blocks);
    }

    public function text(string $blockId): ?string
    {
        if (! isset($this->blocks[$blockId])) {
            return null;
        }

        return $this->texts[$blockId] ??= self::nodeText($this->blocks[$blockId]);
    }

    /** @param  array<string, mixed>  $node */
    public static function nodeText(array $node): string
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
                $buffer .= self::nodeText($child);
            }
        }

        return $buffer;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $blocks
     */
    private static function index(array $node, array &$blocks): void
    {
        $blockId = is_array($node['attrs'] ?? null) ? ($node['attrs']['blockId'] ?? null) : null;
        if (is_string($blockId) && ! isset($blocks[$blockId])) {
            $blocks[$blockId] = $node;
        }

        if (is_array($node['content'] ?? null)) {
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    /** @var array<string, mixed> $child */
                    self::index($child, $blocks);
                }
            }
        }
    }
}
