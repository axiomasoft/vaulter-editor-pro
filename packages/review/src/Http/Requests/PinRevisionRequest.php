<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Requests;

use DateTimeImmutable;
use Illuminate\Validation\Rule;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\PinRevisionInput;
use Vaulter\EditorPro\Review\Enums\PinReason;
use Vaulter\Http\Requests\VaulterFormRequest;

/** Request payload for `POST review/documents/{node}/revisions/{revision}/pin`. */
#[Internal]
final class PinRevisionRequest extends VaulterFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pin_reason' => ['sometimes', 'string', Rule::in(array_map(static fn (PinReason $r): string => $r->value, PinReason::cases()))],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    public function toData(): PinRevisionInput
    {
        $rawReason = $this->input('pin_reason');
        $reason = is_string($rawReason) ? PinReason::from($rawReason) : PinReason::ManualVersion;
        $rawExpires = $this->input('expires_at');
        $expiresAt = is_string($rawExpires) && $rawExpires !== '' ? new DateTimeImmutable($rawExpires) : null;

        return new PinRevisionInput($reason, $expiresAt);
    }
}
