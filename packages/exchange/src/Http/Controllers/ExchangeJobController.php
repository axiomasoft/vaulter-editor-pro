<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Drive\Http\Support\NodeRequestContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;
use Vaulter\EditorPro\Exchange\ExchangeManager;
use Vaulter\EditorPro\Exchange\Http\Requests\ApplyExchangeImportRequest;
use Vaulter\EditorPro\Exchange\Http\Requests\RegisterExchangeJobRequest;
use Vaulter\EditorPro\Exchange\Http\Resources\ExchangeApplyReceiptResource;
use Vaulter\EditorPro\Exchange\Http\Resources\ExchangeJobResource;
use Vaulter\EditorPro\Exchange\Http\Support\ExchangeDriveHandle;

/** HTTP endpoints for server-side DOCX exchange jobs, diagnostics, file downloads, and atomic apply. */
#[Internal]
final readonly class ExchangeJobController
{
    public function __construct(
        private NodeRequestContext $contexts,
        private Vaulter $vaulter,
        private ExchangeManager $exchange,
    ) {}

    public function store(RegisterExchangeJobRequest $request, string $node): JsonResponse
    {
        $drive = ExchangeDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: true);
        $outcome = $this->exchange->forDrive($drive)->register($node, $request->toData());
        $job = $outcome['job'];

        $status = ($outcome['created'] || $job->state === ExchangeJobState::Queued || $job->state === ExchangeJobState::Running)
            ? 202
            : 200;

        $response = (new ExchangeJobResource($job))->response()->setStatusCode($status);
        if (! $outcome['created'] && $request->headers->has('Idempotency-Key')) {
            $response->headers->set('Idempotent-Replayed', 'true');
        }

        return $response;
    }

    public function show(Request $request, string $node, string $job): JsonResponse
    {
        $drive = ExchangeDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node));
        $includePreview = $request->boolean('include_preview', true);
        $preview = $this->exchange->forDrive($drive)->status($node, $job, $includePreview);

        return (new ExchangeJobResource($preview))->response();
    }

    public function file(Request $request, string $node, string $job): StreamedResponse
    {
        $drive = ExchangeDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node));
        $download = $this->exchange->forDrive($drive)->download($node, $job);

        $headers = [
            'Content-Type' => $download->mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ];
        if ($download->sha256 !== null) {
            $headers['ETag'] = '"'.$download->sha256.'"';
        }

        return response()->streamDownload(function () use ($download): void {
            try {
                fpassthru($download->stream);
            } finally {
                if (is_resource($download->stream)) {
                    fclose($download->stream);
                }
            }
        }, $download->filename, $headers);
    }

    public function apply(ApplyExchangeImportRequest $request, string $node, string $job): JsonResponse
    {
        // Pass withIdempotency: false so AtomicDocumentMutation runs cleanly without nested key conflicts;
        // apply() enforces durable idempotency via apply_command_id + apply_receipt_id on v_document_exchange_jobs.
        $drive = ExchangeDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: false);
        $receipt = $this->exchange->forDrive($drive)->apply($node, $job, $request->toData());

        $response = (new ExchangeApplyReceiptResource($receipt))
            ->response()
            ->setStatusCode($receipt->pendingCommit ? 202 : 200);

        if ($receipt->replayed && $request->headers->has('Idempotency-Key')) {
            $response->headers->set('Idempotent-Replayed', 'true');
        }

        return $response;
    }
}
