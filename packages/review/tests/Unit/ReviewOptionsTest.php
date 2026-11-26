<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\EditorPro\Review\Configuration\ReviewOptions;

// The package TestCase migrates on boot; without a transaction the teardown
// rollback of review migrations collides with exchange foreign keys on PostgreSQL.
uses(RefreshDatabase::class);

it('compiles the shipped config file to the constructor defaults', function (): void {
    /** @var array<string, mixed> $shipped */
    $shipped = require dirname(__DIR__, 2).'/config/vaulter-editor-pro-review.php';

    expect(ReviewOptions::fromArray($shipped))->toEqual(new ReviewOptions)
        ->and(ReviewOptions::fromArray([]))->toEqual(new ReviewOptions);
});

it('accepts the documented minimums and overrides', function (): void {
    $options = ReviewOptions::fromArray([
        'pins' => ['enforce_retention' => false],
        'anchors' => [
            'max_selector_bytes' => ReviewOptions::MIN_SELECTOR_BYTES,
            'max_selector_depth' => ReviewOptions::MIN_SELECTOR_DEPTH,
            'max_quote_bytes' => ReviewOptions::MIN_QUOTE_BYTES,
        ],
    ]);

    expect($options->enforceRetention)->toBeFalse()
        ->and($options->maxSelectorBytes)->toBe(256)
        ->and($options->maxSelectorDepth)->toBe(2)
        ->and($options->maxQuoteBytes)->toBe(64);
});

it('reports every invalid path at once instead of falling back to defaults', function (): void {
    try {
        ReviewOptions::fromArray([
            'pin' => [],
            'pins' => ['enforce_retention' => 'yes'],
            'anchors' => [
                'max_selector_bytes' => 255,
                'max_selector_depth' => '16',
                'max_quote_bytes' => 63,
                'max_quote_byte' => 64,
            ],
        ]);
        $this->fail('Invalid configuration was accepted.');
    } catch (InvalidConfiguration $exception) {
        expect($exception->violations)->toBe([
            'vaulter-editor-pro-review.pin: unknown key (did you mean "pins"?)',
            'vaulter-editor-pro-review.anchors.max_quote_byte: unknown key (did you mean "max_quote_bytes"?)',
            'vaulter-editor-pro-review.pins.enforce_retention: must be a boolean.',
            'vaulter-editor-pro-review.anchors.max_selector_bytes: must be an integer of at least 256.',
            'vaulter-editor-pro-review.anchors.max_selector_depth: must be an integer of at least 2.',
            'vaulter-editor-pro-review.anchors.max_quote_bytes: must be an integer of at least 64.',
        ]);
    }
});

it('rejects a section that is not a map', function (): void {
    expect(fn () => ReviewOptions::fromArray(['anchors' => 'strict']))
        ->toThrow(InvalidConfiguration::class, 'vaulter-editor-pro-review.anchors: must be an array.');
});

it('fails the container resolution for a misconfigured host', function (): void {
    config()->set('vaulter-editor-pro-review.anchors.max_selector_depth', 1);

    expect(fn () => app(ReviewOptions::class))
        ->toThrow(InvalidConfiguration::class, 'vaulter-editor-pro-review.anchors.max_selector_depth');
});

it('distinguishes explicit nulls and invalid root values from omitted defaults', function (): void {
    expect(fn () => ReviewOptions::fromArray(['pins' => null]))->toThrow(InvalidConfiguration::class, 'vaulter-editor-pro-review.pins:')
        ->and(fn () => ReviewOptions::fromArray(['pins' => ['enforce_retention' => null]]))->toThrow(InvalidConfiguration::class, 'enforce_retention:')
        ->and(fn () => ReviewOptions::fromArray(['anchors' => ['max_selector_depth' => null]]))->toThrow(InvalidConfiguration::class, 'max_selector_depth:');
    config()->set('vaulter-editor-pro-review', false);
    expect(fn () => app(ReviewOptions::class))->toThrow(InvalidConfiguration::class, 'vaulter-editor-pro-review: must be an array.');
});
