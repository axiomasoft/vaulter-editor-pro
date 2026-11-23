<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Requests;

use Illuminate\Validation\Rule;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\CommentAnchorInput;
use Vaulter\EditorPro\Review\Enums\SelectorKind;
use Vaulter\Http\Requests\VaulterFormRequest;

/** Request payload for `POST review/nodes/{node}/comments/{comment}/reanchor`. */
#[Internal]
final class ReanchorCommentRequest extends VaulterFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $kinds = array_map(static fn (SelectorKind $k): string => $k->value, SelectorKind::cases());

        return [
            'generation' => ['required', 'integer', 'min:0'],
            'source_position' => ['required', 'integer', 'min:0'],
            'schema_version' => ['required', 'integer', 'min:1'],
            'block_id' => ['required', 'string', 'min:1', 'max:255'],
            'selector_kind' => ['required', 'string', Rule::in($kinds)],
            'selector_payload' => ['required', 'array'],
            'quote_text' => ['present', 'string', 'max:4096'],
            'pin_revision_id' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function toData(): CommentAnchorInput
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->all();

        return AddAnchoredCommentRequest::toAnchorInput($validated);
    }
}
