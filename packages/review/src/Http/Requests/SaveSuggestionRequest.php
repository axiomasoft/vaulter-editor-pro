<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Requests;

use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\StructuralSuggestionInput;
use Vaulter\EditorPro\Review\Data\TextSuggestionInput;
use Vaulter\EditorPro\Review\Enums\SuggestionKind;
use Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException;
use Vaulter\Http\Requests\VaulterFormRequest;

/** Request payload for `POST review/documents/{node}/suggestions`. */
#[Internal]
final class SaveSuggestionRequest extends VaulterFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:64'],
            'group_id' => ['required', 'string', 'max:64'],
            'generation' => ['required', 'integer', 'min:0'],
            'base_position' => ['required', 'integer', 'min:0'],
            'schema_version' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'string', 'max:64'],
            'target_block_id' => ['required', 'string', 'min:1', 'max:255'],
            'proposal_id' => ['nullable', 'string', 'max:64'],
            'parent_id' => ['nullable', 'string', 'max:64'],
            'expected_version' => ['sometimes', 'integer', 'min:0'],
            'from' => ['sometimes', 'integer', 'min:0'],
            'to' => ['sometimes', 'integer', 'min:0'],
            'before' => ['sometimes', 'string'],
            'after' => ['sometimes', 'string'],
            'target_selector' => ['sometimes', 'array'],
            'payload' => ['sometimes', 'array'],
        ];
    }

    public function expectedVersion(): ?int
    {
        return $this->has('expected_version') ? self::intVal($this->input('expected_version')) : null;
    }

    public function toData(): TextSuggestionInput|StructuralSuggestionInput
    {
        $kind = self::strVal($this->input('kind', ''));
        $parsedKind = SuggestionKind::tryFrom($kind);
        if ($parsedKind === null) {
            throw UnsupportedSuggestionException::because(
                sprintf('Suggestion kind [%s] is not supported.', $kind),
                'unsupported_suggestion_kind',
            );
        }

        if ($parsedKind->isText()) {
            return new TextSuggestionInput(
                id: self::strVal($this->input('id')),
                groupId: self::strVal($this->input('group_id')),
                generation: self::intVal($this->input('generation')),
                basePosition: self::intVal($this->input('base_position')),
                schemaVersion: self::intVal($this->input('schema_version')),
                targetBlockId: self::strVal($this->input('target_block_id')),
                from: self::intVal($this->input('from', 0)),
                to: self::intVal($this->input('to', 0)),
                before: self::strVal($this->input('before', '')),
                after: self::strVal($this->input('after', '')),
                proposalId: is_string($this->input('proposal_id')) ? $this->input('proposal_id') : null,
                parentId: is_string($this->input('parent_id')) ? $this->input('parent_id') : null,
            );
        }

        $rawSelector = $this->input('target_selector', []);
        /** @var array<string, mixed> $selector */
        $selector = is_array($rawSelector) ? $this->stringMap($rawSelector) : [];
        if (! isset($selector['from_utf16']) && is_int($this->input('from'))) {
            $selector['from_utf16'] = $this->input('from');
        }
        if (! isset($selector['to_utf16']) && is_int($this->input('to'))) {
            $selector['to_utf16'] = $this->input('to');
        }

        $rawPayload = $this->input('payload', []);
        /** @var array<string, mixed> $payload */
        $payload = is_array($rawPayload) ? $this->stringMap($rawPayload) : [];

        return new StructuralSuggestionInput(
            id: self::strVal($this->input('id')),
            groupId: self::strVal($this->input('group_id')),
            generation: self::intVal($this->input('generation')),
            basePosition: self::intVal($this->input('base_position')),
            schemaVersion: self::intVal($this->input('schema_version')),
            kind: $kind,
            targetBlockId: self::strVal($this->input('target_block_id')),
            selector: $selector,
            payload: $payload,
            proposalId: is_string($this->input('proposal_id')) ? $this->input('proposal_id') : null,
            parentId: is_string($this->input('parent_id')) ? $this->input('parent_id') : null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private function stringMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private static function strVal(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function intVal(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
