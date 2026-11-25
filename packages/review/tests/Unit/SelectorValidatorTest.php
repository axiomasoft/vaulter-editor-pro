<?php

declare(strict_types=1);

use Vaulter\EditorPro\Review\Anchors\SelectorValidator;
use Vaulter\EditorPro\Review\Configuration\ReviewOptions;
use Vaulter\EditorPro\Review\Data\CommentAnchorInput;
use Vaulter\EditorPro\Review\Enums\SelectorKind;
use Vaulter\EditorPro\Review\Exceptions\InvalidAnchorSelectorException;

/*
 * Anchor selectors arrive from the browser and are stored as JSON next to the comment, so the
 * validator is the only gate on their bounds and shape. Each refusal must name its reason: the
 * reason is what the API returns in the problem details.
 */

/** @param  array<string, mixed>  $overrides */
function selectorInput(array $overrides = []): CommentAnchorInput
{
    $values = $overrides + [
        'generation' => 3,
        'sourcePosition' => 10,
        'schemaVersion' => 1,
        'blockId' => 'p1',
        'selectorKind' => SelectorKind::SnapshotUtf16,
        'selectorPayload' => ['from_utf16' => 2, 'to_utf16' => 7, 'prefix' => 'Pay ', 'suffix' => ' days'],
        'quoteText' => 'within',
    ];

    return new CommentAnchorInput(...$values);
}

function selectorRefusal(CommentAnchorInput $input, ?ReviewOptions $options = null): ?string
{
    try {
        (new SelectorValidator($options ?? new ReviewOptions))->validateAndNormalize($input);
    } catch (InvalidAnchorSelectorException $exception) {
        return $exception->problemDetails()['reason'];
    }

    return null;
}

it('canonicalizes a snapshot selector and drops fields the client is not allowed to set', function (): void {
    $payload = (new SelectorValidator(new ReviewOptions))->validateAndNormalize(selectorInput([
        'selectorPayload' => ['from_utf16' => 2, 'to_utf16' => 7, 'block_id' => 'other', 'color' => 'red'],
    ]));

    expect($payload)->toBe([
        'schema' => 1,
        'block_id' => 'p1',
        'from_utf16' => 2,
        'to_utf16' => 7,
        'prefix' => '',
        'suffix' => '',
    ]);
});

it('canonicalizes a Yjs selector with the non-widening boundary polarity', function (): void {
    $rel = base64_encode('relative-position');
    $payload = (new SelectorValidator(new ReviewOptions))->validateAndNormalize(selectorInput([
        'selectorKind' => SelectorKind::YjsRelative,
        'selectorPayload' => ['start' => ['rel' => $rel, 'assoc' => 0, 'x' => 1], 'end' => ['rel' => $rel, 'assoc' => -1]],
    ]));

    expect($payload)->toBe([
        'schema' => 1,
        'block_id' => 'p1',
        'start' => ['rel' => $rel, 'assoc' => 0],
        'end' => ['rel' => $rel, 'assoc' => -1],
    ]);
});

it('refuses out-of-range header fields', function (array $overrides, string $reason): void {
    expect(selectorRefusal(selectorInput($overrides)))->toBe($reason);
})->with([
    'negative generation' => [['generation' => -1], 'invalid_generation'],
    'generation past 2^53 - 1' => [['generation' => SelectorValidator::MAX_SAFE_POSITION + 1], 'invalid_generation'],
    'negative source position' => [['sourcePosition' => -1], 'invalid_source_position'],
    'schema version 0' => [['schemaVersion' => 0], 'invalid_schema_version'],
    'empty block id' => [['blockId' => ''], 'invalid_block_id'],
    'blank block id' => [['blockId' => '   '], 'invalid_block_id'],
    'block id over 255 bytes' => [['blockId' => str_repeat('b', 256)], 'invalid_block_id'],
    'quote over the configured cap' => [['quoteText' => str_repeat('q', 4097)], 'quote_too_large'],
]);

it('refuses payloads that are empty, too deep, too large or carry identity fields', function (array $payload, string $reason): void {
    $options = new ReviewOptions(maxSelectorBytes: 512, maxSelectorDepth: 4);

    expect(selectorRefusal(selectorInput(['selectorPayload' => $payload]), $options))->toBe($reason);
})->with([
    'empty' => [[], 'empty_payload'],
    'nested past the depth cap' => [['from_utf16' => 0, 'to_utf16' => 1, 'x' => ['a' => ['b' => ['c' => ['d' => 1]]]]], 'payload_depth'],
    'over the byte cap' => [['from_utf16' => 0, 'to_utf16' => 1, 'x' => str_repeat('a', 600)], 'payload_too_large'],
    'author at the top level' => [['from_utf16' => 0, 'to_utf16' => 1, 'author_id' => 7], 'author_spoof_rejected'],
    'resolved_by nested, any case' => [['from_utf16' => 0, 'to_utf16' => 1, 'meta' => ['Resolved_By' => 7]], 'author_spoof_rejected'],
]);

it('refuses malformed snapshot bounds and context', function (array $payload, string $reason): void {
    expect(selectorRefusal(selectorInput(['selectorPayload' => $payload])))->toBe($reason);
})->with([
    'missing bounds' => [['prefix' => 'a'], 'invalid_utf16_bounds'],
    'string bounds' => [['from_utf16' => '1', 'to_utf16' => '4'], 'invalid_utf16_bounds'],
    'negative start' => [['from_utf16' => -1, 'to_utf16' => 4], 'invalid_utf16_bounds'],
    'empty range' => [['from_utf16' => 4, 'to_utf16' => 4], 'invalid_utf16_bounds'],
    'non-string prefix' => [['from_utf16' => 0, 'to_utf16' => 4, 'prefix' => 5], 'invalid_context'],
    'suffix over 512 bytes' => [['from_utf16' => 0, 'to_utf16' => 4, 'suffix' => str_repeat('s', 513)], 'context_too_large'],
]);

it('refuses malformed Yjs relative positions', function (array $payload, string $reason): void {
    expect(selectorRefusal(selectorInput(['selectorKind' => SelectorKind::YjsRelative, 'selectorPayload' => $payload])))->toBe($reason);
})->with([
    'missing end' => [['start' => ['rel' => 'AA==', 'assoc' => 0]], 'missing_yjs_bounds'],
    'empty rel' => [['start' => ['rel' => '', 'assoc' => 0], 'end' => ['rel' => 'AA==', 'assoc' => -1]], 'invalid_yjs_rel'],
    'rel not base64' => [['start' => ['rel' => '***', 'assoc' => 0], 'end' => ['rel' => 'AA==', 'assoc' => -1]], 'invalid_base64'],
    'widening start polarity' => [['start' => ['rel' => 'AA==', 'assoc' => -1], 'end' => ['rel' => 'AA==', 'assoc' => -1]], 'invalid_yjs_assoc'],
    'widening end polarity' => [['start' => ['rel' => 'AA==', 'assoc' => 0], 'end' => ['rel' => 'AA==', 'assoc' => 0]], 'invalid_yjs_assoc'],
]);
