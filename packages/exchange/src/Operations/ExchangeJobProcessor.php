<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Operations;

use Illuminate\Contracts\Container\Container;
use JsonException;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
use Vaulter\EditorPro\Exchange\Contracts\BeforeArtifactLinkHook;
use Vaulter\EditorPro\Exchange\Contracts\DocxExchangeRuntime;
use Vaulter\EditorPro\Exchange\Data\ExchangeJobData;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobKind;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeJobRepository;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeLookup;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeReviewStateStore;
use Vaulter\EditorPro\Exchange\Runtime\CanonicalJson;
use Vaulter\EditorPro\Exchange\Security\DocxZipArchiveGuard;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * Worker processor executing queued/expired DOCX import and export jobs with lease fencing,
 * pre-parse archive security inspection, TipTap normalization, and crash-safe Media artifact pinning.
 */
#[Internal]
final readonly class ExchangeJobProcessor
{
    public function __construct(
        private DocumentsManager $documents,
        private BlobStore $blobs,
        private DocxZipArchiveGuard $zipGuard,
        private DocxExchangeRuntime $runtime,
        private ExchangeJobRepository $jobs,
        private ExchangeLookup $lookup,
        private ExchangeReviewStateStore $reviewState,
        private ExchangeOptions $options,
        private Container $container,
    ) {}

    public function process(string $jobId, DriveHandle $drive): ?ExchangeJobData
    {
        $token = $this->jobs->claimLease($jobId, $this->options->leaseSeconds);
        if ($token === null) {
            return $this->jobs->findById($jobId);
        }

        $job = $this->jobs->findById($jobId);
        if ($job === null) {
            return null;
        }

        try {
            $nodeId = $this->lookup->nodeIdForDocument($job->documentId);
            if ($nodeId === null) {
                throw ExchangeException::jobNotFound($jobId);
            }

            $driveSnap = $drive->snapshot();
            $blobScope = BlobScope::forUpload('tenant', $driveSnap->tenantId, $driveSnap->id);
            $docs = $this->documents->in($drive);

            $nodeSnap = $drive->nodes()->find($nodeId);
            $abilities = $drive->nodes()->abilities($nodeSnap)->flags;

            if ($job->kind === ExchangeJobKind::Import) {
                if (($abilities[Ability::Edit->value] ?? false) !== true) {
                    throw PermissionDeniedException::forAbility(Ability::Edit->value);
                }
                $head = $docs->content($nodeSnap);
                if (! $head->editable || $head->type !== 'vaulter/rich-text') {
                    throw ExchangeException::unsupportedDocument('uneditable_or_unsupported_type');
                }

                return $this->processImport($job, $token, $blobScope, $nodeSnap->id, $head->epoch, $head->stateVersion);
            }

            if (($abilities[Ability::Download->value] ?? false) !== true) {
                throw PermissionDeniedException::forAbility(Ability::Download->value);
            }

            return $this->processExport($job, $token, $blobScope, $drive, $nodeSnap->id);
        } catch (ExchangeException $error) {
            if ($error->httpStatus() >= 500) {
                $this->jobs->releaseLeaseToQueued($jobId, $token);
                throw $error;
            }
            if ($error->problemCode() === 'exchange_lease_lost') {
                throw $error;
            }

            return $this->jobs->markFailed($jobId, $token, $error->problemCode(), $error->problemDetails());
        } catch (NodeNotFoundException|PermissionDeniedException|ProfileRestrictionException $denied) {
            return $this->jobs->markFailed($jobId, $token, 'permission_denied', ['reason' => $denied->getMessage()]);
        } catch (Throwable $unexpected) {
            $this->jobs->releaseLeaseToQueued($jobId, $token);
            throw $unexpected;
        }
    }

    private function processImport(
        ExchangeJobData $job,
        string $token,
        BlobScope $blobScope,
        string $nodeId,
        int $headGeneration,
        int $headPosition,
    ): ExchangeJobData {
        $sourceBlobId = $job->sourceBlobId;
        if ($sourceBlobId === null || $sourceBlobId === '') {
            throw ExchangeException::sourceUnavailable('missing_source_blob_id');
        }

        $bytes = $this->readBlobBounded($sourceBlobId, $this->options->maxImportBytes);

        // Strict pre-parse ZIP/XML/external-rel inspection BEFORE invoking Node parser.
        $this->zipGuard->assertSafe($bytes);

        $marker = [
            'nodeId' => $nodeId,
            'documentId' => $job->documentId,
            'generation' => $job->sourceGeneration ?? $headGeneration,
            'position' => $job->sourcePosition ?? $headPosition,
        ];

        $result = $this->runtime->importDocx($bytes, $job->profileId, $marker);

        $report = $result->report;
        $report['blocked'] = $result->blocked || ($report['blocked'] ?? false) === true;
        $normalizedDoc = $this->normalizeTipTapForStore($result->document, $report);
        $sections = $result->sections;
        $discussions = $result->discussions;
        $revisions = $result->revisions;
        $this->reviewState->resolvePreviewBlockBindings($normalizedDoc, $discussions, $revisions);
        $report['source_head'] = [
            'generation' => $job->sourceGeneration ?? $headGeneration,
            'position' => $job->sourcePosition ?? $headPosition,
        ];
        if ($result->semantic !== null) {
            $report['semantic'] = $result->semantic;
        }

        $previewEnvelope = CanonicalJson::encode([
            'schema' => 'vaulter.docx.preview/v1',
            'job_id' => $job->id,
            'document_id' => $job->documentId,
            'profile' => $job->profileId,
            'source_generation' => $job->sourceGeneration ?? $headGeneration,
            'source_position' => $job->sourcePosition ?? $headPosition,
            'document' => $normalizedDoc,
            'sections' => $sections,
            'discussions' => $discussions,
            'revisions' => $revisions,
            'report' => $report,
        ]);

        // Commit output preview artifact to Media BEFORE acquiring finalize lock and pinning.
        // If worker dies before finalize transaction commits, Media GC cleans the unpinned orphan.
        $outputBlob = $this->commitBlobBytes($blobScope, $previewEnvelope);
        $this->invokeBeforeLinkHook($job->id, $outputBlob);

        return $this->jobs->finalizeOutput(
            $job->id,
            $token,
            ExchangeJobState::PreviewReady,
            $outputBlob->id,
            $report,
        );
    }

    private function processExport(
        ExchangeJobData $job,
        string $token,
        BlobScope $blobScope,
        DriveHandle $drive,
        string $nodeId,
    ): ExchangeJobData {
        $snapshotPayload = null;
        if ($job->sourceBlobId !== null && $job->sourceBlobId !== '') {
            $rawJson = $this->readBlobBounded($job->sourceBlobId, $this->options->maxExportBytes);
            try {
                $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $snapshotPayload = $decoded;
                }
            } catch (JsonException $e) {
                throw ExchangeException::sourceUnavailable('corrupt_export_snapshot', $e);
            }
        }

        if ($snapshotPayload === null) {
            $snap = $this->documents->in($drive)->content($nodeId, $job->sourceRevisionId);
            $gen = $snap->revision !== null ? $snap->revision->epoch : $snap->epoch;
            $snapshotPayload = [
                'document' => $snap->content->toArray(),
                'source_marker' => [
                    'nodeId' => $nodeId,
                    'documentId' => $snap->documentId,
                    'revisionId' => $snap->revision?->id,
                    'generation' => $gen,
                    'position' => $snap->revision !== null ? $snap->revision->stateVersion : $snap->stateVersion,
                ],
                'sections' => $this->reviewState->loadSections($snap->documentId, $gen),
                'discussions' => $this->reviewState->loadDiscussions($drive, $nodeId, $snap->documentId, $gen),
                'revisions' => $job->sourceRevisionId === null
                    ? $this->reviewState->loadOpenSuggestions($snap->documentId, $gen)
                    : [],
                'options' => [],
            ];
        }

        $rawDoc = $snapshotPayload['document'] ?? null;
        if (! is_array($rawDoc)) {
            throw ExchangeException::sourceUnavailable('missing_document_payload');
        }

        $docMap = self::stringMap($rawDoc);
        $rawMarker = is_array($snapshotPayload['source_marker'] ?? null) ? self::stringMap($snapshotPayload['source_marker']) : null;
        $sections = self::listOfStringMaps($snapshotPayload['sections'] ?? null);
        $discussions = self::listOfStringMaps($snapshotPayload['discussions'] ?? null);
        $revisions = self::listOfStringMaps($snapshotPayload['revisions'] ?? null);
        $rawOptions = is_array($snapshotPayload['options'] ?? null) ? self::stringMap($snapshotPayload['options']) : [];

        $result = $this->runtime->exportDocx(
            document: $docMap,
            profileId: $job->profileId,
            sourceMarker: $rawMarker,
            discussions: $discussions,
            options: $rawOptions,
            sections: $sections,
            revisions: $revisions,
        );

        $report = $result->report;
        if ($sections === [] && $discussions === [] && $revisions === []) {
            $this->recordLoss(
                $report,
                'body_only_export',
                'document',
                'Only the stored document body is available for export. Section layout, headers, footers, and document discussions are omitted.',
                action: 'projected',
            );
        }
        if ($result->semantic !== null) {
            $report['semantic'] = $result->semantic;
        }
        if ($result->blocked || $result->docxBytes === null) {
            return $this->jobs->markFailed($job->id, $token, ExchangeException::profileBlocked()->problemCode(), $report);
        }

        // Commit immutable DOCX artifact to Media BEFORE pinning & updating v_document_exchange_jobs.
        // Worker death here leaves an unpinned orphan cleaned by Media GC.
        $outputBlob = $this->commitBlobBytes($blobScope, $result->docxBytes);
        $this->invokeBeforeLinkHook($job->id, $outputBlob);

        $report['output_sha256'] = $result->docxSha256 ?? hash('sha256', $result->docxBytes);
        $report['output_bytes'] = strlen($result->docxBytes);

        return $this->jobs->finalizeOutput(
            $job->id,
            $token,
            ExchangeJobState::Completed,
            $outputBlob->id,
            $report,
        );
    }

    /**
     * Normalize imported TipTap JSON against active host `TipTapSchema::richText()`,
     * scrub unsafe link/image URLs, assign unique `blockId` values, and verify strict validity.
     *
     * @param  array<string, mixed>  $rawDoc
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function normalizeTipTapForStore(array $rawDoc, array &$report): array
    {
        $schema = TipTapSchema::richText();
        [$decayed, $lossless] = TipTapContent::decayReporting($rawDoc, $schema);
        if (! $lossless) {
            $this->recordLoss(
                $report,
                'host_schema_loss',
                'document',
                'The document schema discarded unsupported nodes, marks, or attributes.',
                action: 'dropped',
            );
        }
        $sanitized = $decayed->withSanitizedUrls()->toArray();
        $withBlockIds = BlockIdentity::assignMissing($sanitized);
        $validated = TipTapContent::fromArray($withBlockIds, $schema);

        return $validated->toArray();
    }

    /** @param array<string, mixed> $report */
    private function recordLoss(array &$report, string $code, string $feature, string $message, string $action): void
    {
        $severity = $action === 'blocked' ? 'error' : 'warning';
        $diagnostics = is_array($report['diagnostics'] ?? null) ? $report['diagnostics'] : [];
        $diagnostics[] = [
            'code' => $code,
            'severity' => $severity,
            'feature' => $feature,
            'location' => 'document',
            'action' => $action,
            'message' => $message,
        ];
        $report['diagnostics'] = $diagnostics;
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $summary[$severity] = (is_int($summary[$severity] ?? null) ? $summary[$severity] : 0) + 1;
        $report['summary'] = $summary;
        if ($action === 'blocked') {
            $report['blocked'] = true;
            $reasons = is_array($report['blocking_reasons'] ?? null) ? $report['blocking_reasons'] : [];
            $reasons[] = $code.' (document): '.$message;
            $report['blocking_reasons'] = $reasons;
        }
    }

    private function readBlobBounded(string $blobId, int $maxBytes): string
    {
        $stream = $this->blobs->read($blobId);
        if (! is_resource($stream)) {
            throw ExchangeException::sourceUnavailable('blob_not_readable');
        }

        try {
            $buf = '';
            while (! feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false) {
                    throw ExchangeException::sourceUnavailable('blob_read_failed');
                }
                if ($chunk === '') {
                    continue;
                }
                $buf .= $chunk;
                if (strlen($buf) > $maxBytes) {
                    throw ExchangeException::zipLimitExceeded('blob_size_limit');
                }
            }

            return $buf;
        } finally {
            fclose($stream);
        }
    }

    private function commitBlobBytes(BlobScope $scope, string $bytes): BlobSnapshot
    {
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw ExchangeException::runtimeFailed('stream_open_failed');
        }

        try {
            fwrite($stream, $bytes);
            rewind($stream);

            return $this->blobs->commit($stream, $scope);
        } finally {
            fclose($stream);
        }
    }

    private function invokeBeforeLinkHook(string $jobId, BlobSnapshot $blob): void
    {
        if ($this->container->bound(BeforeArtifactLinkHook::class)) {
            $this->container->make(BeforeArtifactLinkHook::class)->beforeLinkArtifact($jobId, $blob);
        }
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function stringMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function listOfStringMaps(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $out[] = self::stringMap($item);
            }
        }

        return $out;
    }
}
