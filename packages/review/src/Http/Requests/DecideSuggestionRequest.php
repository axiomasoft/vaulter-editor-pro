<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Requests;

use Packman\Attributes\Internal;
use Vaulter\Http\Requests\VaulterFormRequest;

/** Request payload for accepting or rejecting suggestions and suggestion groups. */
#[Internal]
final class DecideSuggestionRequest extends VaulterFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'generation' => ['required', 'integer', 'min:0'],
            'expected_version' => ['sometimes', 'integer', 'min:1'],
            'expected_versions' => ['sometimes', 'array', 'min:1'],
            'expected_versions.*' => ['integer', 'min:1'],
            'command_id' => ['nullable', 'string', 'min:1', 'max:255'],
        ];
    }

    public function generation(): int
    {
        return self::intVal($this->input('generation', 0), 0);
    }

    public function expectedVersion(): ?int
    {
        return $this->has('expected_version') ? self::intVal($this->input('expected_version'), 0) : null;
    }

    /** @return array<string, int> */
    public function expectedVersions(): array
    {
        $raw = $this->input('expected_versions', []);
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id => $ver) {
            if (is_string($id) && is_numeric($ver)) {
                $out[$id] = (int) $ver;
            }
        }

        return $out;
    }

    public function commandId(): ?string
    {
        $raw = $this->input('command_id');

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    private static function intVal(mixed $value, int $default): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }
}
