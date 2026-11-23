<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Exchange\Data\ApplyExchangeImportInput;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;

/** Request validator for `POST /documents/{node}/exchange-jobs/{job}/apply`. */
#[Internal]
final class ApplyExchangeImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'apply_command_id' => ['nullable', 'string', 'min:1', 'max:255'],
            'command_id' => ['nullable', 'string', 'min:1', 'max:255'],
            'expected_generation' => ['nullable', 'integer', 'min:0'],
            'expected_position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function toData(): ApplyExchangeImportInput
    {
        $commandId = $this->string('apply_command_id')->trim()->toString();
        if ($commandId === '') {
            $commandId = $this->string('command_id')->trim()->toString();
        }
        if ($commandId === '') {
            $commandId = trim($this->headers->get('Idempotency-Key') ?? '');
        }
        if ($commandId === '') {
            throw ExchangeException::invalidCommand('apply_command_id is required.');
        }

        $gen = $this->input('expected_generation');
        $pos = $this->input('expected_position');

        return new ApplyExchangeImportInput(
            applyCommandId: $commandId,
            expectedGeneration: is_int($gen) ? $gen : (is_string($gen) && ctype_digit($gen) ? (int) $gen : null),
            expectedPosition: is_int($pos) ? $pos : (is_string($pos) && ctype_digit($pos) ? (int) $pos : null),
        );
    }
}
