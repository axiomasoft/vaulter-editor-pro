<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Exchange\Data\ExchangeJobData;
use Vaulter\EditorPro\Exchange\Data\ExchangePreviewData;

/**
 * JSON resource representing `v_document_exchange_jobs` row, diagnostics report, and optional preview.
 *
 * @property ExchangePreviewData|ExchangeJobData $resource
 */
#[Internal]
final class ExchangeJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $preview = $this->resource instanceof ExchangePreviewData ? $this->resource : null;
        $job = $preview !== null ? $preview->job : $this->resource;

        return [
            'id' => $job->id,
            'document_id' => $job->documentId,
            'request_key' => $job->requestKey,
            'kind' => $job->kind->value,
            'format' => $job->format->value,
            'profile_id' => $job->profileId,
            'state' => $job->state->value,
            'source_blob_id' => $job->sourceBlobId,
            'source_revision_id' => $job->sourceRevisionId,
            'source_generation' => $job->sourceGeneration,
            'source_position' => $job->sourcePosition,
            'output_blob_id' => $job->outputBlobId,
            'apply_command_id' => $job->applyCommandId,
            'apply_receipt_id' => $job->applyReceiptId,
            'diagnostics_report' => $job->diagnosticsReport,
            'error_code' => $job->errorCode,
            'preview' => $preview?->previewDocument,
            'semantic' => $preview !== null ? $preview->semantic : ($job->diagnosticsReport['semantic'] ?? null),
            'created_at' => $job->createdAt->format(DATE_ATOM),
            'updated_at' => $job->updatedAt->format(DATE_ATOM),
            'finished_at' => $job->finishedAt?->format(DATE_ATOM),
        ];
    }
}
