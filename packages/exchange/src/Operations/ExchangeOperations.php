<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Operations;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Collaboration\Configuration\CollaborationOptions;
use Vaulter\Collaboration\Persistence\CommandActor;
use Vaulter\Collaboration\Persistence\CommandJournal;
use Vaulter\Collaboration\Persistence\RoomWriteConflict;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\Contracts\AtomicDocumentMutation;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
use Vaulter\EditorPro\Exchange\Data\ApplyExchangeImportInput;
use Vaulter\EditorPro\Exchange\Data\ApplyExchangeReceiptData;
use Vaulter\EditorPro\Exchange\Data\ExchangeDownloadData;
use Vaulter\EditorPro\Exchange\Data\ExchangeJobData;
use Vaulter\EditorPro\Exchange\Data\ExchangePreviewData;
use Vaulter\EditorPro\Exchange\Data\RegisterExchangeJobInput;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobKind;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\EditorPro\Exchange\Http\Support\ExchangeDriveHandle;
use Vaulter\EditorPro\Exchange\Idempotency\ExchangeJobReceiptCodec;
use Vaulter\EditorPro\Exchange\Idempotency\ExchangeJobReplayAuthorizer;
use Vaulter\EditorPro\Exchange\Jobs\RunDocxExchangeJob;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeJobRepository;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeLookup;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeReviewStateStore;
use Vaulter\EditorPro\Exchange\Runtime\CanonicalJson;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Support\Identity;

/**
 * Public DOCX exchange operations scoped to one drive context:
 * job registration (import/export), status/preview retrieval, ACL-checked artifact download,
 * worker processing, and atomic CAS import application across snapshot (`AtomicDocumentMutation`)
 * and strict journal (`CommandJournal`) write modes.
 */
#[Api(since: '1.0')]
final readonly class ExchangeOperations implements DriveExtension
{
    private const string DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    #[Internal]
    public function __construct(
        private DriveContext $context,
        private Vaulter $vaulter,
        private DocumentsManager $documents,
        private AtomicDocumentMutation $mutations,
        private VaulterDatabase $database,
        private OperationRunner $runner,
        private BlobStore $blobs,
        private ExchangeJobRepository $jobs,
        private ExchangeJobProcessor $processor,
        private ExchangeLookup $lookup,
        private ExchangeReviewStateStore $reviewState,
        private ExchangeOptions $options,
        private Container $container,
    ) {}

    /** @return array{created: bool, job: ExchangeJobData} */
    public function register(NodeSnapshot|string|int $document, RegisterExchangeJobInput $input): array
    {
        $requestKey = trim($input->requestKey);
        if ($requestKey === '' || strlen($requestKey) > 255) {
            throw ExchangeException::invalidCommand('request_key must contain 1..255 bytes.');
        }

        if (! in_array($input->format->value, $this->options->formats, true)) {
            throw ExchangeException::formatDisabled($input->format->value);
        }

        $profileId = $input->profileId !== null && trim($input->profileId) !== ''
            ? trim($input->profileId)
            : $this->options->defaultProfile;
        if (! in_array($profileId, $this->options->profiles, true)) {
            throw ExchangeException::invalidCommand('Unsupported DOCX profile "'.$profileId.'".', 'invalid_profile');
        }

        $drive = $this->activeDrive();
        $nodeSnap = $drive->nodes()->find($document);
        $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
        $docMeta = $this->lookup->documentForNode($nodeSnap->id);
        $docs = $this->documents->in($drive);

        if ($input->kind === ExchangeJobKind::Import) {
            if (($abilities[Ability::Edit->value] ?? false) !== true) {
                throw PermissionDeniedException::forAbility(Ability::Edit->value);
            }
            $head = $docs->content($nodeSnap);
            if (! $head->editable || $head->type !== 'vaulter/rich-text') {
                throw ExchangeException::unsupportedDocument('document_not_editable_richtext');
            }
            if ($input->sourceGeneration !== null && $input->sourceGeneration !== $head->epoch) {
                throw ExchangeException::stalePreview('generation_mismatch');
            }
            if ($input->sourcePosition !== null && $input->sourcePosition !== $head->stateVersion) {
                throw ExchangeException::stalePreview('position_mismatch');
            }

            $sourceBlobId = $this->resolveImportSourceBlobId($drive, $input);
            $sourceRevisionId = null;
            $sourceGeneration = $head->epoch;
            $sourcePosition = $head->stateVersion;
            $fingerprintInput = [
                'kind' => 'import',
                'format' => $input->format->value,
                'profile_id' => $profileId,
                'source_blob_id' => $sourceBlobId,
                ...($input->rawBytes === null && $input->sourceNodeId !== null ? [
                    'source_node_id' => $input->sourceNodeId,
                    'source_revision_id' => $input->sourceRevisionId,
                ] : []),
                'options' => $input->options,
            ];
            $requiredAbility = Ability::Edit;
        } else {
            if (($abilities[Ability::Download->value] ?? false) !== true) {
                throw PermissionDeniedException::forAbility(Ability::Download->value);
            }

            if ($input->sourceRevisionId !== null) {
                $targetSnap = $docs->content($nodeSnap, $input->sourceRevisionId);
                $revision = $targetSnap->revision;
                if ($revision !== null) {
                    $sourceRevisionId = $revision->id;
                    $sourceGeneration = $revision->epoch;
                    $sourcePosition = $revision->stateVersion;
                } else {
                    $sourceRevisionId = $input->sourceRevisionId;
                    $sourceGeneration = $targetSnap->epoch;
                    $sourcePosition = $targetSnap->stateVersion;
                }
            } else {
                $targetSnap = $docs->content($nodeSnap);
                $sourceRevisionId = null;
                $sourceGeneration = $input->sourceGeneration ?? $targetSnap->epoch;
                $sourcePosition = $input->sourcePosition ?? $targetSnap->stateVersion;

                if ($sourceGeneration !== $targetSnap->epoch || $sourcePosition !== $targetSnap->stateVersion) {
                    throw ExchangeException::stalePreview('export_head_mismatch');
                }
            }

            if ($targetSnap->type !== 'vaulter/rich-text') {
                throw ExchangeException::unsupportedDocument('unsupported_document_type');
            }

            $sections = is_array($input->options['sections'] ?? null)
                ? array_values(array_filter($input->options['sections'], is_array(...)))
                : $this->reviewState->loadSections($docMeta['id'], $sourceGeneration);
            $discussions = is_array($input->options['discussions'] ?? null)
                ? array_values(array_filter($input->options['discussions'], is_array(...)))
                : $this->reviewState->loadDiscussions($drive, $nodeSnap->id, $docMeta['id'], $sourceGeneration);
            $revisions = $sourceRevisionId === null
                ? $this->reviewState->loadOpenSuggestions($docMeta['id'], $sourceGeneration)
                : [];

            $snapshotEnvelope = CanonicalJson::encode([
                'document' => $targetSnap->content->toArray(),
                'source_marker' => [
                    'nodeId' => $nodeSnap->id,
                    'documentId' => $targetSnap->documentId,
                    'revisionId' => $sourceRevisionId,
                    'generation' => $sourceGeneration,
                    'position' => $sourcePosition,
                    'schemaVersion' => $targetSnap->schemaVersion,
                    'contentHash' => $targetSnap->content->contentHash(),
                    'projection' => is_string($input->options['projection'] ?? null) ? $input->options['projection'] : 'original',
                ],
                'sections' => $sections,
                'discussions' => $discussions,
                'revisions' => $revisions,
                'options' => $input->options,
            ]);
            $sourceBlobId = $this->commitBlobBytes($snapshotEnvelope);
            $fingerprintInput = [
                'kind' => 'export',
                'format' => $input->format->value,
                'profile_id' => $profileId,
                'source_revision_id' => $sourceRevisionId,
                'source_generation' => $sourceGeneration,
                'source_position' => $sourcePosition,
                'content_hash' => $targetSnap->content->contentHash(),
                ...($sections !== [] ? ['sections_hash' => CanonicalJson::sha256Hex($sections)] : []),
                ...($discussions !== [] ? ['discussions_hash' => CanonicalJson::sha256Hex($discussions)] : []),
                ...($revisions !== [] ? ['revisions_hash' => CanonicalJson::sha256Hex($revisions)] : []),
                'options' => $input->options,
            ];
            $requiredAbility = Ability::Download;
        }

        $fingerprintHex = $this->resolveFingerprintHex($input->requestFingerprintHex, $fingerprintInput);
        $tenantId = $docMeta['tenant_id'];
        $projectScope = ExchangeLookup::projectScope($this->context);
        $effectiveSubject = ExchangeLookup::effectiveSubject($this->context);
        $documentId = $docMeta['id'];

        $idempotency = new IdempotentWrite(
            codec: new ExchangeJobReceiptCodec($nodeSnap->id),
            authorizer: new ExchangeJobReplayAuthorizer($this->context, $this->vaulter, $nodeSnap->id, $requiredAbility),
            fingerprint: [
                'node' => $nodeSnap->id,
                'request_key' => $requestKey,
                'fingerprint' => $fingerprintHex,
            ],
        );

        /** @var array{created: bool, job: ExchangeJobData} $outcome */
        $outcome = $this->runner->write(
            new OperationSpec('exchange.jobs.register', new ModuleId('exchange')),
            $this->context->operation,
            function () use (
                $tenantId,
                $projectScope,
                $effectiveSubject,
                $documentId,
                $requestKey,
                $fingerprintHex,
                $input,
                $profileId,
                $sourceBlobId,
                $sourceRevisionId,
                $sourceGeneration,
                $sourcePosition,
            ): array {
                try {
                    $res = $this->jobs->findOrCreate(
                        tenantId: $tenantId,
                        projectScope: $projectScope,
                        effectiveSubject: $effectiveSubject,
                        documentId: $documentId,
                        requestKey: $requestKey,
                        fingerprintHex: $fingerprintHex,
                        kind: $input->kind,
                        format: $input->format,
                        profileId: $profileId,
                        sourceBlobId: $sourceBlobId,
                        sourceRevisionId: $sourceRevisionId,
                        sourceGeneration: $sourceGeneration,
                        sourcePosition: $sourcePosition,
                    );
                } catch (ModelNotFoundException|BlobUnavailableException $e) {
                    throw ExchangeException::sourceUnavailable('blob_unavailable', $e);
                }

                if ($res['created'] && $this->options->autoDispatch) {
                    $jobId = $res['job']->id;
                    $this->database->afterCommit(fn () => RunDocxExchangeJob::enqueue(
                        $jobId, Identity::stringify($tenantId), $projectScope, $effectiveSubject, $this->options,
                    ));
                }

                return $res;
            },
            $idempotency,
        );

        if ($outcome['created'] && $this->options->autoDispatch) {
            $refreshed = $this->jobs->findById($outcome['job']->id);
            if ($refreshed !== null) {
                return ['created' => true, 'job' => $refreshed];
            }
        }

        return $outcome;
    }

    /** Execute a single queued or lease-expired exchange job directly on behalf of the current drive handle. */
    public function process(string $jobId): ?ExchangeJobData
    {
        return $this->processor->process($jobId, $this->activeDrive());
    }

    public function status(NodeSnapshot|string|int $document, string $jobId, bool $includePreview = true): ExchangePreviewData
    {
        $drive = $this->activeDrive();
        $nodeSnap = $drive->nodes()->find($document);
        $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
        if (($abilities[Ability::View->value] ?? false) !== true) {
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }
        $docMeta = $this->lookup->documentForNode($nodeSnap->id);

        $job = $this->jobs->findForActor(
            tenantId: $docMeta['tenant_id'],
            projectScope: ExchangeLookup::projectScope($this->context),
            effectiveSubject: ExchangeLookup::effectiveSubject($this->context),
            documentId: $docMeta['id'],
            jobId: $jobId,
        );

        if ($job->kind === ExchangeJobKind::Import
            && $job->state === ExchangeJobState::PreviewReady
            && $job->applyCommandId !== null
            && $docMeta['write_mode'] === 'journal') {
            $job = $this->syncPendingJournalState($nodeSnap->id, $job) ?? $job;
        }

        $previewDoc = null;
        $semantic = is_array($job->diagnosticsReport['semantic'] ?? null) ? self::stringMap($job->diagnosticsReport['semantic']) : null;
        if ($includePreview
            && $job->kind === ExchangeJobKind::Import
            && ($job->state === ExchangeJobState::PreviewReady || $job->state === ExchangeJobState::Applied)
            && $job->outputBlobId !== null) {
            $previewPayload = $this->readImportPreviewArtifact($job->outputBlobId);
            $previewDoc = is_array($previewPayload['document'] ?? null) ? self::stringMap($previewPayload['document']) : null;
        }

        $applied = $job->state === ExchangeJobState::Applied;

        return new ExchangePreviewData(
            job: $job,
            previewDocument: $previewDoc,
            semantic: $semantic,
            writeMode: $applied ? $docMeta['write_mode'] : null,
            appliedGeneration: $applied ? $docMeta['collab_epoch'] : null,
            appliedPosition: $applied ? $docMeta['state_version'] : null,
        );
    }

    public function download(NodeSnapshot|string|int $document, string $jobId): ExchangeDownloadData
    {
        $drive = $this->activeDrive();
        $nodeSnap = $drive->nodes()->find($document);
        $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
        if (($abilities[Ability::Download->value] ?? false) !== true) {
            throw PermissionDeniedException::forAbility(Ability::Download->value);
        }

        $docMeta = $this->lookup->documentForNode($nodeSnap->id);

        $job = $this->jobs->findForActor(
            tenantId: $docMeta['tenant_id'],
            projectScope: ExchangeLookup::projectScope($this->context),
            effectiveSubject: ExchangeLookup::effectiveSubject($this->context),
            documentId: $docMeta['id'],
            jobId: $jobId,
        );

        if ($job->kind !== ExchangeJobKind::Export) {
            throw ExchangeException::invalidCommand('Only export exchange jobs produce downloadable DOCX files.');
        }
        if ($job->state === ExchangeJobState::Queued || $job->state === ExchangeJobState::Running) {
            throw ExchangeException::jobNotReady($job->state->value);
        }
        if ($job->state !== ExchangeJobState::Completed || $job->outputBlobId === null) {
            throw ExchangeException::jobNotReady($job->state->value);
        }

        $stream = $this->blobs->read($job->outputBlobId);
        if (! is_resource($stream)) {
            throw ExchangeException::sourceUnavailable('output_blob_missing');
        }

        $baseName = pathinfo($nodeSnap->name, PATHINFO_FILENAME);
        $cleaned = preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName);
        $safeBase = is_string($cleaned) && $cleaned !== '' ? $cleaned : 'document';
        $sha = is_string($job->diagnosticsReport['output_sha256'] ?? null) ? $job->diagnosticsReport['output_sha256'] : null;

        return new ExchangeDownloadData(
            stream: $stream,
            filename: $safeBase.'.'.$job->format->value,
            mimeType: self::DOCX_MIME,
            sha256: $sha,
        );
    }

    /**
     * Explicitly apply an import preview job once ACL and head generation/position match.
     * Supports both `snapshot` (`AtomicDocumentMutation`) and strict `journal` (`CommandJournal`) write modes.
     */
    public function apply(NodeSnapshot|string|int $document, string $jobId, ApplyExchangeImportInput $input): ApplyExchangeReceiptData
    {
        $applyCommandId = trim($input->applyCommandId);
        if ($applyCommandId === '' || strlen($applyCommandId) > 255) {
            throw ExchangeException::invalidCommand('apply_command_id must contain 1..255 bytes.');
        }

        $drive = $this->activeDrive();
        $nodeSnap = $drive->nodes()->find($document);
        $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
        if (($abilities[Ability::Edit->value] ?? false) !== true) {
            throw PermissionDeniedException::forAbility(Ability::Edit->value);
        }

        $docMeta = $this->lookup->documentForNode($nodeSnap->id);
        $tenantId = $docMeta['tenant_id'];
        $projectScope = ExchangeLookup::projectScope($this->context);
        $effectiveSubject = ExchangeLookup::effectiveSubject($this->context);

        $job = $this->jobs->findForActor($tenantId, $projectScope, $effectiveSubject, $docMeta['id'], $jobId);
        if ($job->kind !== ExchangeJobKind::Import) {
            throw ExchangeException::invalidCommand('Only import exchange jobs can be applied.');
        }
        if ($job->state === ExchangeJobState::Queued || $job->state === ExchangeJobState::Running) {
            throw ExchangeException::jobNotReady($job->state->value);
        }
        if ($job->state === ExchangeJobState::Failed) {
            throw ExchangeException::unsafeArchive($job->errorCode ?? 'import_failed');
        }

        if ($job->state === ExchangeJobState::Applied) {
            if ($job->applyCommandId !== $applyCommandId) {
                throw ExchangeException::alreadyApplied();
            }

            return new ApplyExchangeReceiptData(
                job: $job,
                applyCommandId: $applyCommandId,
                applyReceiptId: $job->applyReceiptId,
                writeMode: $docMeta['write_mode'],
                generation: $docMeta['collab_epoch'],
                position: $docMeta['state_version'],
                replayed: true,
            );
        }

        if ((bool) ($job->diagnosticsReport['blocked'] ?? false) === true) {
            throw ExchangeException::profileBlocked();
        }
        if ($job->outputBlobId === null) {
            throw ExchangeException::sourceUnavailable('preview_artifact_missing');
        }

        $preview = $this->readImportPreviewArtifact($job->outputBlobId);
        $rawDoc = $preview['document'] ?? null;
        if (! is_array($rawDoc)) {
            throw ExchangeException::sourceUnavailable('preview_document_corrupt');
        }
        $normalizedDoc = $this->normalizeTipTapArray(self::stringMap($rawDoc));

        if ($docMeta['write_mode'] === 'journal') {
            return $this->applyViaJournal($nodeSnap->id, $docMeta, $job, $applyCommandId, $normalizedDoc, $preview, $input);
        }

        return $this->applyViaSnapshotMutation($nodeSnap->id, $job, $applyCommandId, $normalizedDoc, $preview, $input);
    }

    /**
     * @param  array<string, mixed>  $normalizedDoc
     * @param  array<string, mixed>  $preview
     */
    private function applyViaSnapshotMutation(
        string $nodeId,
        ExchangeJobData $initialJob,
        string $applyCommandId,
        array $normalizedDoc,
        array $preview,
        ApplyExchangeImportInput $input,
    ): ApplyExchangeReceiptData {
        /** @var ApplyExchangeReceiptData $receipt */
        $receipt = $this->mutations->run($this->context, $nodeId, function (LockedDocumentMutation $locked) use (
            $initialJob,
            $applyCommandId,
            $normalizedDoc,
            $preview,
            $input,
            $nodeId,
        ): ApplyExchangeReceiptData {
            $state = $locked->snapshot();
            $lockedJob = $this->jobs->lockForApply($locked->connection(), $initialJob->id, $state->documentId);

            if ($lockedJob->state === ExchangeJobState::Applied) {
                if ($lockedJob->applyCommandId !== $applyCommandId) {
                    throw ExchangeException::alreadyApplied();
                }

                return new ApplyExchangeReceiptData(
                    job: $lockedJob,
                    applyCommandId: $applyCommandId,
                    applyReceiptId: $lockedJob->applyReceiptId,
                    writeMode: $state->writeMode,
                    generation: $state->generation,
                    position: $state->position,
                    replayed: true,
                );
            }

            if ($lockedJob->state !== ExchangeJobState::PreviewReady) {
                throw ExchangeException::jobNotReady($lockedJob->state->value);
            }
            if ($state->writeMode !== 'snapshot') {
                throw ExchangeException::stalePreview('write_mode_changed');
            }
            if ($state->pending) {
                throw ExchangeException::stalePreview('document_state_pending');
            }

            $this->assertPreviewHead($lockedJob, $input, $state->generation, $state->position);

            $content = TipTapContent::fromArray($normalizedDoc, TipTapSchema::richText());
            $next = $locked->replace($content, $state->position);
            $receiptId = (string) Str::uuid7();

            $updatedJob = $this->jobs->markAppliedOnConnection(
                $locked->connection(),
                $lockedJob->id,
                $applyCommandId,
                $receiptId,
            );

            $this->reviewState->admitImportedSuggestions($locked->connection(), $lockedJob->documentId, $next->generation, $preview);
            $this->reviewState->persistImportedStateOnConnection(
                conn: $locked->connection(),
                context: $this->context,
                documentId: $state->documentId,
                nodeId: $nodeId,
                jobId: $lockedJob->id,
                generation: $next->generation,
                position: $next->position,
                schemaVersion: $next->schemaVersion,
                previewPayload: $preview,
            );

            return new ApplyExchangeReceiptData(
                job: $updatedJob,
                applyCommandId: $applyCommandId,
                applyReceiptId: $receiptId,
                writeMode: $next->writeMode,
                generation: $next->generation,
                position: $next->position,
                replayed: false,
            );
        });

        return $receipt;
    }

    private function assertPreviewHead(ExchangeJobData $job, ApplyExchangeImportInput $input, int $generation, int $position): void
    {
        if ($job->sourceGeneration !== $generation || ($input->expectedGeneration !== null && $input->expectedGeneration !== $generation)) {
            throw ExchangeException::stalePreview('generation_changed');
        }
        if ($job->sourcePosition !== $position || ($input->expectedPosition !== null && $input->expectedPosition !== $position)) {
            throw ExchangeException::stalePreview('position_changed');
        }
    }

    /**
     * @param  array{id: int|string, node_id: int|string, tenant_id: int|string, type: string, collab_epoch: int, state_version: int, schema_version: int, write_mode: string}  $docMeta
     * @param  array<string, mixed>  $normalizedDoc
     * @param  array<string, mixed>  $preview
     */
    private function applyViaJournal(
        string $nodeId,
        array $docMeta,
        ExchangeJobData $job,
        string $applyCommandId,
        array $normalizedDoc,
        array $preview,
        ApplyExchangeImportInput $input,
    ): ApplyExchangeReceiptData {
        $journal = $this->resolveCommandJournal();
        if ($journal === null) {
            throw ExchangeException::unsupportedDocument('collaboration_journal_unavailable');
        }
        $actor = $this->buildCommandActor($nodeId, $docMeta['tenant_id']);

        try {
            return $this->database->transaction(function (Connection $conn) use (
                $journal, $actor, $nodeId, $job, $input, $applyCommandId, $normalizedDoc, $preview,
            ): ApplyExchangeReceiptData {
                // Status locks document authority first, matching journal and snapshot lock order.
                $record = $journal->status($actor, $applyCommandId);
                $lockedJob = $this->jobs->lockForApply($conn, $job->id, $job->documentId);
                $meta = $this->lookup->documentForNode($nodeId);

                if ($lockedJob->applyCommandId !== null && $lockedJob->applyCommandId !== $applyCommandId) {
                    throw $lockedJob->state === ExchangeJobState::Applied
                        ? ExchangeException::alreadyApplied()
                        : ExchangeException::idempotencyConflict('apply_command_id_mismatch');
                }

                if ($lockedJob->state === ExchangeJobState::Applied) {
                    return new ApplyExchangeReceiptData(
                        job: $lockedJob,
                        applyCommandId: $applyCommandId,
                        applyReceiptId: $lockedJob->applyReceiptId,
                        writeMode: 'journal',
                        generation: $meta['collab_epoch'],
                        position: $meta['state_version'],
                        replayed: true,
                    );
                }

                if ($lockedJob->state !== ExchangeJobState::PreviewReady) {
                    throw ExchangeException::jobNotReady($lockedJob->state->value);
                }

                $generation = $lockedJob->sourceGeneration;
                $position = $lockedJob->sourcePosition;
                if ($generation === null || $position === null) {
                    throw ExchangeException::stalePreview('preview_head_missing');
                }

                $replayed = $record !== null;
                $slots = 0;
                $readPosition = $position;
                $intent = [
                    'operation' => 'exchange.apply_import',
                    'exchange_job_id' => $lockedJob->id,
                    'format' => $lockedJob->format->value,
                    'profile_id' => $lockedJob->profileId,
                ];
                if ($record === null) {
                    $this->assertPreviewHead($lockedJob, $input, $meta['collab_epoch'], $meta['state_version']);
                    if ($meta['write_mode'] !== 'journal') {
                        throw ExchangeException::stalePreview('write_mode_changed');
                    }

                    $slots = $this->reviewState->admitImportedSuggestions($conn, $lockedJob->documentId, $generation, $preview);
                    $projected = $journal->projected($actor, $generation, $position);
                    if ($projected === null) {
                        throw ExchangeException::unsupportedDocument('collaboration_journal_unavailable');
                    }
                    $readPosition = $projected['position'];
                    $basePosition = $readPosition;
                    // The strict room seeds from JSON before it runs commands, unless it already has a checkpoint.
                    if ($readPosition === 0 && $this->container->make(CollaborativeState::class)->lock($nodeId)->collabState === null) {
                        $basePosition = 1;
                    }
                    $intent += [
                        'base_generation' => $generation,
                        'base_position' => $basePosition,
                        'atomic_effects' => [
                            ['effect' => 'exchange.apply_import', 'replacement' => $normalizedDoc],
                        ],
                    ];
                } else {
                    // Preserve the fingerprint of commands registered before the job link existed.
                    $intent['document'] = $normalizedDoc;
                }

                if ($record === null || $lockedJob->applyCommandId === null) {
                    $record = $journal->register(
                        scope: $actor,
                        key: $applyCommandId,
                        proposal: self::deterministicUuid('proposal:'.$lockedJob->id.':'.$applyCommandId),
                        digest: CanonicalJson::sha256Hex([
                            'exchange_job_id' => $lockedJob->id,
                            'document_id' => $lockedJob->documentId,
                            'apply_command_id' => $applyCommandId,
                            'generation' => $generation,
                            'position' => $position,
                            'document' => $normalizedDoc,
                        ]),
                        generation: $generation,
                        schema: is_int($record['schema_version'] ?? null) ? $record['schema_version'] : $meta['schema_version'],
                        readPosition: $readPosition,
                        intent: $intent,
                    );
                }

                $journalState = $record['state'] ?? null;
                if ($journalState === 'rejected') {
                    $reason = $record['rejection_code'] ?? null;
                    throw ExchangeException::stalePreview(is_string($reason) ? $reason : 'journal_rejected');
                }

                $receiptId = $record['receipt_id'] ?? null;
                $committed = $journalState === 'committed' && is_string($receiptId);
                if ($committed) {
                    $updatedJob = $this->jobs->markAppliedOnConnection($conn, $lockedJob->id, $applyCommandId, $receiptId);
                    $settledGen = is_int($record['generation'] ?? null) ? $record['generation'] : $generation;
                    $settledPos = is_int($record['to_seq'] ?? null) ? $record['to_seq'] : $meta['state_version'];
                    $this->reviewState->persistImportedStateOnConnection(
                        conn: $conn,
                        context: $this->context,
                        documentId: $lockedJob->documentId,
                        nodeId: $nodeId,
                        jobId: $lockedJob->id,
                        generation: $settledGen,
                        position: $settledPos,
                        schemaVersion: $meta['schema_version'],
                        previewPayload: $preview,
                    );
                } else {
                    $commandRowId = $record['id'] ?? null;
                    if ($slots > 0 && ! is_string($commandRowId)) {
                        throw ExchangeException::runtimeFailed('journal_command_id_missing');
                    }
                    $updatedJob = $this->jobs->recordPendingJournalApply($conn, $lockedJob, $applyCommandId, is_string($commandRowId) ? $commandRowId : null, $slots);
                }

                return new ApplyExchangeReceiptData(
                    job: $updatedJob,
                    applyCommandId: $applyCommandId,
                    applyReceiptId: $committed ? $receiptId : null,
                    writeMode: 'journal',
                    generation: is_int($record['generation'] ?? null) ? $record['generation'] : $generation,
                    position: is_int($record['to_seq'] ?? null) ? $record['to_seq'] : $position,
                    replayed: $replayed,
                    pendingCommit: ! $committed,
                );
            });
        } catch (RoomWriteConflict $conflict) {
            throw match ($conflict->reason) {
                'key_conflict' => ExchangeException::idempotencyConflict('journal_key_conflict'),
                default => ExchangeException::stalePreview($conflict->reason),
            };
        }
    }

    private function syncPendingJournalState(string $nodeId, ExchangeJobData $job): ?ExchangeJobData
    {
        if ($job->applyCommandId === null) {
            return null;
        }

        $journal = $this->resolveCommandJournal();
        if ($journal === null) {
            return null;
        }

        $docMeta = $this->lookup->documentForNode($nodeId);
        $actor = $this->buildCommandActor($nodeId, $docMeta['tenant_id']);

        try {
            $record = $journal->status($actor, $job->applyCommandId);
        } catch (RoomWriteConflict) {
            return null;
        }

        if ($record === null || ($record['state'] ?? null) !== 'committed' || ! is_string($record['receipt_id'] ?? null)) {
            return null;
        }

        $receiptId = $record['receipt_id'];
        $applyCommandId = $job->applyCommandId;
        $preview = $job->outputBlobId !== null ? $this->readImportPreviewArtifact($job->outputBlobId) : [];
        $settledGen = is_int($record['generation'] ?? null) ? $record['generation'] : $docMeta['collab_epoch'];
        $settledPos = is_int($record['to_seq'] ?? null) ? $record['to_seq'] : $docMeta['state_version'];

        return $this->database->transaction(function (Connection $conn) use (
            $job, $applyCommandId, $receiptId, $preview, $nodeId, $settledGen, $settledPos, $docMeta,
        ): ExchangeJobData {
            $this->container->make(CollaborativeState::class)->lock($nodeId);
            $locked = $this->jobs->lockForApply($conn, $job->id, $job->documentId);
            if ($locked->state === ExchangeJobState::Applied) {
                return $locked;
            }

            $applied = $this->jobs->markAppliedOnConnection($conn, $job->id, $applyCommandId, $receiptId);
            if ($preview !== []) {
                $this->reviewState->persistImportedStateOnConnection(
                    conn: $conn,
                    context: $this->context,
                    documentId: $job->documentId,
                    nodeId: $nodeId,
                    jobId: $job->id,
                    generation: $settledGen,
                    position: $settledPos,
                    schemaVersion: $docMeta['schema_version'],
                    previewPayload: $preview,
                );
            }

            return $applied;
        });
    }

    private function resolveImportSourceBlobId(DriveHandle $drive, RegisterExchangeJobInput $input): string
    {
        if ($input->rawBytes !== null) {
            if (strlen($input->rawBytes) > $this->options->maxImportBytes) {
                throw ExchangeException::zipLimitExceeded('import_size_limit');
            }

            return $this->commitBlobBytes($input->rawBytes);
        }

        if ($input->sourceNodeId !== null && $input->sourceNodeId !== '') {
            try {
                $fileStream = $drive->files()->read($input->sourceNodeId, $input->sourceRevisionId);
            } catch (NodeNotFoundException) {
                throw ExchangeException::sourceUnavailable('source_node_not_found');
            } catch (PermissionDeniedException|ProfileRestrictionException) {
                throw PermissionDeniedException::forAbility(Ability::Download->value);
            }

            $bytes = '';
            try {
                while (! feof($fileStream->stream)) {
                    $chunk = fread($fileStream->stream, 65_536);
                    if ($chunk === false) {
                        throw ExchangeException::sourceUnavailable('source_stream_failed');
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $bytes .= $chunk;
                    if (strlen($bytes) > $this->options->maxImportBytes) {
                        throw ExchangeException::zipLimitExceeded('import_size_limit');
                    }
                }
            } finally {
                fclose($fileStream->stream);
            }

            return $this->commitBlobBytes($bytes);
        }

        if ($input->sourceBlobId !== null && trim($input->sourceBlobId) !== '') {
            throw PermissionDeniedException::forAbility(Ability::Download->value);
        }

        throw ExchangeException::invalidCommand('Import requires an uploaded file or an authorized source.node_id.');
    }

    /**
     * @param  array<string, mixed>  $fingerprintInput
     */
    private function resolveFingerprintHex(?string $clientHex, array $fingerprintInput): string
    {
        if ($clientHex !== null && $clientHex !== '') {
            $norm = strtolower(trim($clientHex));
            if (preg_match('/\A[0-9a-f]{64}\z/', $norm) !== 1) {
                throw ExchangeException::invalidCommand('request_fingerprint must be a 64-character hex SHA-256 digest.');
            }
        }

        return CanonicalJson::sha256Hex($fingerprintInput);
    }

    private function commitBlobBytes(string $bytes): string
    {
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw ExchangeException::runtimeFailed('stream_open_failed');
        }

        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $blob = $this->blobs->commit(
                $stream,
                BlobScope::forUpload(
                    'tenant',
                    $this->context->operation->tenant->id,
                    $this->context->operation->scope->id,
                ),
            );

            return $blob->id;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readImportPreviewArtifact(string $blobId): array
    {
        $stream = $this->blobs->read($blobId);
        if (! is_resource($stream)) {
            throw ExchangeException::sourceUnavailable('preview_blob_missing');
        }

        try {
            $raw = stream_get_contents($stream, $this->options->maxImportBytes + 1);
            if (! is_string($raw) || strlen($raw) > $this->options->maxImportBytes) {
                throw ExchangeException::sourceUnavailable('preview_blob_unreadable');
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw ExchangeException::sourceUnavailable('preview_blob_invalid');
            }

            return self::stringMap($decoded);
        } catch (JsonException $e) {
            throw ExchangeException::sourceUnavailable('preview_blob_invalid_json', $e);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function normalizeTipTapArray(array $doc): array
    {
        $schema = TipTapSchema::richText();
        [$decayed, $lossless] = TipTapContent::decayReporting($doc, $schema);
        if (! $lossless) {
            throw ExchangeException::profileBlocked('host_schema_loss');
        }
        $withBlockIds = BlockIdentity::assignMissing($decayed->withSanitizedUrls()->toArray());
        try {
            return TipTapContent::fromArray($withBlockIds, $schema)->toArray();
        } catch (InvalidDocumentContentException $e) {
            throw ExchangeException::unsupportedDocument($e->getMessage());
        }
    }

    private function resolveCommandJournal(): ?CommandJournal
    {
        if (! class_exists(CommandJournal::class) || ! class_exists(CollaborationOptions::class)) {
            return null;
        }
        if (! $this->container->bound(CommandJournal::class) && ! $this->container->bound(CollaborationOptions::class)) {
            return null;
        }

        try {
            return $this->container->make(CommandJournal::class);
        } catch (Throwable) {
            return null;
        }
    }

    private function buildCommandActor(string $nodeId, int|string $tenantId): CommandActor
    {
        $ref = $this->context->actor->ref();
        $driveTenant = $this->context->operation->tenant->id;
        $rawTenant = $driveTenant !== null ? $driveTenant : (string) $tenantId;
        $commandTenant = preg_match('/\A(?:0|[1-9][0-9]*)\z/', $rawTenant) === 1 && (string) (int) $rawTenant === $rawTenant
            ? $rawTenant
            : null;

        return new CommandActor(
            principal: $ref,
            tenant: $commandTenant,
            project: ExchangeLookup::projectScope($this->context),
            subject: ExchangeLookup::effectiveSubject($this->context),
            driveId: $this->context->operation->scope->id,
            resourceId: $nodeId,
        );
    }

    private function activeDrive(): DriveHandle
    {
        $drive = ExchangeDriveHandle::fromContext($this->vaulter, $this->context);
        if ($drive->snapshot()->status !== DriveStatus::Active) {
            throw DriveArchivedException::forDrive($drive->snapshot()->id);
        }

        return $drive;
    }

    private static function deterministicUuid(string $seed): string
    {
        $hash = hash('sha256', $seed);
        $b = substr($hash, 0, 32);
        $timeHi = (hexdec(substr($b, 12, 4)) & 0x0FFF) | 0x4000;
        $clockSeq = (hexdec(substr($b, 16, 4)) & 0x3FFF) | 0x8000;

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($b, 0, 8),
            substr($b, 8, 4),
            $timeHi,
            $clockSeq,
            substr($b, 20, 12),
        );
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
}
