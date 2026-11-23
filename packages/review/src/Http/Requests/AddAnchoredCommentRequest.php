<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Requests;

use Illuminate\Validation\Rule;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\AddAnchoredCommentInput;
use Vaulter\EditorPro\Review\Data\CommentAnchorInput;
use Vaulter\EditorPro\Review\Enums\SelectorKind;
use Vaulter\Http\Requests\VaulterFormRequest;

/** Request payload for `POST review/nodes/{node}/comments`. */
#[Internal]
final class AddAnchoredCommentRequest extends VaulterFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $kinds = array_map(static fn (SelectorKind $k): string => $k->value, SelectorKind::cases());

        return [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'string', 'max:191'],
            'anchor' => ['nullable', 'array'],
            'anchor.generation' => ['required_with:anchor', 'integer', 'min:0'],
            'anchor.source_position' => ['required_with:anchor', 'integer', 'min:0'],
            'anchor.schema_version' => ['required_with:anchor', 'integer', 'min:1'],
            'anchor.block_id' => ['required_with:anchor', 'string', 'min:1', 'max:255'],
            'anchor.selector_kind' => ['required_with:anchor', 'string', Rule::in($kinds)],
            'anchor.selector_payload' => ['required_with:anchor', 'array'],
            'anchor.quote_text' => ['present_with:anchor', 'string', 'max:4096'],
            'anchor.pin_revision_id' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function toData(): AddAnchoredCommentInput
    {
        $rawAnchor = $this->nullableArrayInput('anchor');
        $anchor = $rawAnchor === null ? null : self::toAnchorInput($rawAnchor);

        return new AddAnchoredCommentInput(
            body: $this->string('body')->toString(),
            parentId: $this->nullableScalarId('parent_id'),
            anchor: $anchor,
        );
    }

    /** @param array<string, mixed> $raw */
    public static function toAnchorInput(array $raw): CommentAnchorInput
    {
        $selectorPayload = $raw['selector_payload'] ?? [];
        /** @var array<string, mixed> $normalizedPayload */
        $normalizedPayload = is_array($selectorPayload) ? $selectorPayload : [];

        return new CommentAnchorInput(
            generation: is_numeric($raw['generation'] ?? null) ? (int) $raw['generation'] : -1,
            sourcePosition: is_numeric($raw['source_position'] ?? null) ? (int) $raw['source_position'] : -1,
            schemaVersion: is_numeric($raw['schema_version'] ?? null) ? (int) $raw['schema_version'] : 0,
            blockId: is_string($raw['block_id'] ?? null) ? $raw['block_id'] : '',
            selectorKind: SelectorKind::from(is_string($raw['selector_kind'] ?? null) ? $raw['selector_kind'] : ''),
            selectorPayload: $normalizedPayload,
            quoteText: is_string($raw['quote_text'] ?? null) ? $raw['quote_text'] : '',
            pinRevisionId: is_scalar($raw['pin_revision_id'] ?? null) && (string) $raw['pin_revision_id'] !== ''
                ? (string) $raw['pin_revision_id']
                : null,
        );
    }
}
