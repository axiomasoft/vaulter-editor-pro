<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Anchors;

use JsonException;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Configuration\ReviewOptions;
use Vaulter\EditorPro\Review\Data\CommentAnchorInput;
use Vaulter\EditorPro\Review\Enums\SelectorKind;
use Vaulter\EditorPro\Review\Exceptions\InvalidAnchorSelectorException;

/**
 * Validates and canonicalizes `snapshot_utf16` and `yjs_relative` anchor payloads,
 * enforcing SQL bounds, JSON depth/size limits, strict `assoc` polarity, and anti-spoofing rules.
 */
#[Internal]
final readonly class SelectorValidator
{
    public const int MAX_SAFE_POSITION = 9007199254740991;

    /** Keys forbidden inside selector payloads to prevent client-side identity or status spoofing. */
    private const array FORBIDDEN_SPOOF_KEYS = [
        'author',
        'author_id',
        'author_type',
        'author_subject',
        'user_id',
        'created_by',
        'resolved_by',
        'resolved_at',
    ];

    public function __construct(private ReviewOptions $options) {}

    /** @return array<string, mixed> sanitized canonical selector payload */
    public function validateAndNormalize(CommentAnchorInput $input): array
    {
        if ($input->generation < 0 || $input->generation > self::MAX_SAFE_POSITION) {
            throw InvalidAnchorSelectorException::because('generation must be within 0..9007199254740991.', 'invalid_generation');
        }
        if ($input->sourcePosition < 0 || $input->sourcePosition > self::MAX_SAFE_POSITION) {
            throw InvalidAnchorSelectorException::because('source_position must be within 0..9007199254740991.', 'invalid_source_position');
        }
        if ($input->schemaVersion < 1) {
            throw InvalidAnchorSelectorException::because('schema_version must be positive.', 'invalid_schema_version');
        }
        $blockIdLen = strlen($input->blockId);
        if ($blockIdLen < 1 || $blockIdLen > 255 || trim($input->blockId) === '') {
            throw InvalidAnchorSelectorException::because('block_id must be a non-empty string up to 255 bytes.', 'invalid_block_id');
        }
        if (strlen($input->quoteText) > $this->options->maxQuoteBytes) {
            throw InvalidAnchorSelectorException::because('quote_text exceeds maximum configured length.', 'quote_too_large');
        }

        $this->assertJsonBounds($input->selectorPayload);
        $this->assertNoSpoofKeys($input->selectorPayload);

        return match ($input->selectorKind) {
            SelectorKind::SnapshotUtf16 => $this->normalizeSnapshotPayload($input->selectorPayload, $input->blockId),
            SelectorKind::YjsRelative => $this->normalizeYjsPayload($input->selectorPayload, $input->blockId),
        };
    }

    /** @param array<string, mixed> $payload */
    private function assertJsonBounds(array $payload): void
    {
        if ($payload === []) {
            throw InvalidAnchorSelectorException::because('selector_payload must be a non-empty object.', 'empty_payload');
        }

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR, $this->options->maxSelectorDepth);
        } catch (JsonException) {
            throw InvalidAnchorSelectorException::because(
                sprintf('selector_payload exceeds max JSON depth of %d or is invalid.', $this->options->maxSelectorDepth),
                'payload_depth',
            );
        }

        if (strlen($encoded) > $this->options->maxSelectorBytes) {
            throw InvalidAnchorSelectorException::because(
                sprintf('selector_payload exceeds %d bytes.', $this->options->maxSelectorBytes),
                'payload_too_large',
            );
        }
    }

    /** @param array<array-key, mixed> $payload */
    private function assertNoSpoofKeys(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_SPOOF_KEYS, true)) {
                throw InvalidAnchorSelectorException::because(
                    sprintf('selector_payload must not contain reserved identity key "%s".', $key),
                    'author_spoof_rejected',
                );
            }
            if (is_array($value)) {
                $this->assertNoSpoofKeys($value);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeSnapshotPayload(array $payload, string $blockId): array
    {
        $from = $payload['from_utf16'] ?? null;
        $to = $payload['to_utf16'] ?? null;
        if (! is_int($from) || ! is_int($to) || $from < 0 || $to <= $from) {
            throw InvalidAnchorSelectorException::because(
                'snapshot_utf16 requires integers from_utf16 >= 0 and to_utf16 > from_utf16.',
                'invalid_utf16_bounds',
            );
        }

        $prefix = $payload['prefix'] ?? '';
        $suffix = $payload['suffix'] ?? '';
        if (! is_string($prefix) || ! is_string($suffix)) {
            throw InvalidAnchorSelectorException::because('prefix and suffix must be strings when provided.', 'invalid_context');
        }
        if (strlen($prefix) > 512 || strlen($suffix) > 512) {
            throw InvalidAnchorSelectorException::because('prefix and suffix must each be at most 512 bytes.', 'context_too_large');
        }

        return [
            'schema' => 1,
            'block_id' => $blockId,
            'from_utf16' => $from,
            'to_utf16' => $to,
            'prefix' => $prefix,
            'suffix' => $suffix,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeYjsPayload(array $payload, string $blockId): array
    {
        $start = $payload['start'] ?? null;
        $end = $payload['end'] ?? null;
        if (! is_array($start) || ! is_array($end)) {
            throw InvalidAnchorSelectorException::because('yjs_relative requires start and end relative position objects.', 'missing_yjs_bounds');
        }

        $startRel = $start['rel'] ?? null;
        $endRel = $end['rel'] ?? null;
        if (! is_string($startRel) || $startRel === '' || ! is_string($endRel) || $endRel === '') {
            throw InvalidAnchorSelectorException::because('start.rel and end.rel must be non-empty base64 strings.', 'invalid_yjs_rel');
        }
        if (base64_decode($startRel, true) === false || base64_decode($endRel, true) === false) {
            throw InvalidAnchorSelectorException::because('start.rel and end.rel must be valid base64.', 'invalid_base64');
        }

        // The start boundary sticks to the character after it (assoc 0) and the end boundary to the character
        // before it (assoc -1), so text typed at either edge of the range does not widen the comment.
        if (($start['assoc'] ?? null) !== 0 || ($end['assoc'] ?? null) !== -1) {
            throw InvalidAnchorSelectorException::because(
                'yjs_relative requires start.assoc === 0 and end.assoc === -1 to prevent boundary expansion.',
                'invalid_yjs_assoc',
            );
        }

        return [
            'schema' => 1,
            'block_id' => $blockId,
            'start' => [
                'rel' => $startRel,
                'assoc' => 0,
            ],
            'end' => [
                'rel' => $endRel,
                'assoc' => -1,
            ],
        ];
    }
}
