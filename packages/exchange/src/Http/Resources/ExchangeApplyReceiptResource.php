<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Exchange\Data\ApplyExchangeReceiptData;

/**
 * JSON resource representing a durable import apply receipt.
 *
 * @property ApplyExchangeReceiptData $resource
 */
#[Internal]
final class ExchangeApplyReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $receipt = $this->resource;

        return [
            'job_id' => $receipt->job->id,
            'document_id' => $receipt->job->documentId,
            'state' => $receipt->job->state->value,
            'apply_command_id' => $receipt->applyCommandId,
            'apply_receipt_id' => $receipt->applyReceiptId,
            'write_mode' => $receipt->writeMode,
            'generation' => $receipt->generation,
            'position' => $receipt->position,
            'replayed' => $receipt->replayed,
            'pending_commit' => $receipt->pendingCommit,
        ];
    }
}
