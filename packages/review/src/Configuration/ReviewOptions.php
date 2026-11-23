<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;

/**
 * Validated runtime configuration options for `vaulter-editor-pro-review`.
 */
#[Api(since: '1.0')]
final readonly class ReviewOptions implements OptionsFactory
{
    /**
     * @param  int<1, max>  $maxSelectorBytes
     * @param  int<1, max>  $maxSelectorDepth
     * @param  int<1, max>  $maxQuoteBytes
     */
    public function __construct(
        public bool $enforceRetention = true,
        public int $maxSelectorBytes = 16384,
        public int $maxSelectorDepth = 16,
        public int $maxQuoteBytes = 4096,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config, string $path = 'vaulter-editor-pro-review'): self
    {
        $pins = is_array($config['pins'] ?? null) ? $config['pins'] : [];
        $anchors = is_array($config['anchors'] ?? null) ? $config['anchors'] : [];

        return new self(
            enforceRetention: (bool) ($pins['enforce_retention'] ?? true),
            maxSelectorBytes: max(256, is_int($anchors['max_selector_bytes'] ?? null) ? $anchors['max_selector_bytes'] : 16384),
            maxSelectorDepth: max(2, is_int($anchors['max_selector_depth'] ?? null) ? $anchors['max_selector_depth'] : 16),
            maxQuoteBytes: max(64, is_int($anchors['max_quote_bytes'] ?? null) ? $anchors['max_quote_bytes'] : 4096),
        );
    }
}
