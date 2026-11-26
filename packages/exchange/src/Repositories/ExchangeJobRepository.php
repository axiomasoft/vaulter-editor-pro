<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Repositories;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use JsonException;
use Packman\Attributes\Internal;
use stdClass;
use Vaulter\Database\VaulterDatabase;
use Vaulter\EditorPro\Exchange\Data\ExchangeJobData;
use Vaulter\EditorPro\Exchange\Enums\ExchangeFormat;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobKind;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Support\Identity;

/** Persistence repository for `v_document_exchange_jobs` with lease/fencing and pin safety. */
#[Internal]
final readonly class ExchangeJobRepository
{
    public const string SOURCE_PIN_OWNER = 'editor_pro.exchange_source';

    public const string OUTPUT_PIN_OWNER = 'editor_pro.exchange_output';

    public function __construct(
        private VaulterDatabase $database,
        private ExchangeLookup $lookup,
        private BlobPins $pins,
    ) {}

    /**
     * Find or register an exchange job inside the current transaction.
     *
     * @return array{created: bool, job: ExchangeJobData}
     */
    public function findOrCreate(
        int|string $tenantId,
        string $projectScope,
        string $effectiveSubject,
        int|string $documentId,
        string $requestKey,
        string $fingerprintHex,
        ExchangeJobKind $kind,
        ExchangeFormat $format,
        string $profileId,
        ?string $sourceBlobId,
        ?string $sourceRevisionId,
        ?int $sourceGeneration,
        ?int $sourcePosition,
    ): array {
        $conn = $this->lookup->connection();
        $scope = [
            'tenant_id' => $tenantId,
            'project_scope' => $projectScope,
            'effective_subject' => $effectiveSubject,
            'document_id' => $documentId,
            'request_key' => $requestKey,
        ];

        $existing = $this->table($conn)->where($scope)->lockForUpdate()->first();
        if ($existing instanceof stdClass) {
            return ['created' => false, 'job' => $this->replayExisting($existing, $fingerprintHex)];
        }

        $jobId = (string) Str::uuid7();
        $now = $this->lookup->dbNow($conn);
        $encodedFingerprint = $this->lookup->encodeFingerprintHex($fingerprintHex, $conn);

        try {
            $inserted = $this->database->transaction(function () use (
                $conn,
                $scope,
                $jobId,
                $encodedFingerprint,
                $kind,
                $format,
                $profileId,
                $sourceBlobId,
                $sourceRevisionId,
                $sourceGeneration,
                $sourcePosition,
                $now,
            ): bool {
                return $this->table($conn)->insert($scope + [
                    'id' => $jobId,
                    'request_fingerprint' => $encodedFingerprint,
                    'kind' => $kind->value,
                    'format' => $format->value,
                    'profile_id' => $profileId,
                    'source_blob_id' => $sourceBlobId,
                    'source_revision_id' => $sourceRevisionId,
                    'source_generation' => $sourceGeneration,
                    'source_position' => $sourcePosition,
                    'output_blob_id' => null,
                    'apply_command_id' => null,
                    'apply_receipt_id' => null,
                    'state' => ExchangeJobState::Queued->value,
                    'diagnostics_report' => null,
                    'error_code' => null,
                    'lease_token' => null,
                    'lease_until' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'finished_at' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $inserted = false;
        }

        if (! $inserted) {
            $winner = $this->table($conn)->where($scope)->lockForUpdate()->first();
            if (! $winner instanceof stdClass) {
                throw ExchangeException::runtimeFailed('registration_race_failed');
            }

            return ['created' => false, 'job' => $this->replayExisting($winner, $fingerprintHex)];
        }

        if ($sourceBlobId !== null) {
            $this->pins->pin($sourceBlobId, self::SOURCE_PIN_OWNER, $jobId);
        }

        $row = $this->table($conn)->where('id', $jobId)->first();
        if (! $row instanceof stdClass) {
            throw ExchangeException::runtimeFailed('inserted_job_missing');
        }

        return ['created' => true, 'job' => $this->toData($row)];
    }

    public function findForActor(
        int|string $tenantId,
        string $projectScope,
        string $effectiveSubject,
        int|string $documentId,
        string $jobId,
    ): ExchangeJobData {
        if (! $this->lookup->isUuid($jobId)) {
            throw ExchangeException::jobNotFound($jobId);
        }

        $row = $this->table()
            ->where([
                'id' => $jobId,
                'tenant_id' => $tenantId,
                'project_scope' => $projectScope,
                'effective_subject' => $effectiveSubject,
                'document_id' => $documentId,
            ])
            ->first();

        if (! $row instanceof stdClass) {
            throw ExchangeException::jobNotFound($jobId);
        }

        return $this->toData($row);
    }

    public function findById(string $jobId, ?Connection $connection = null): ?ExchangeJobData
    {
        if (! $this->lookup->isUuid($jobId)) {
            return null;
        }

        $row = $this->table($connection)->where('id', $jobId)->first();

        return $row instanceof stdClass ? $this->toData($row) : null;
    }

    /**
     * Raw row lookup returning scope metadata needed for queued worker recovery.
     *
     * @return array{id: string, tenant_id: string, project_scope: string, effective_subject: string, document_id: string, state: string}|null
     */
    public function findRawMetadata(string $jobId): ?array
    {
        if (! $this->lookup->isUuid($jobId)) {
            return null;
        }

        $row = $this->table()
            ->where('id', $jobId)
            ->first(['id', 'tenant_id', 'project_scope', 'effective_subject', 'document_id', 'state']);

        if (! $row instanceof stdClass) {
            return null;
        }

        return [
            'id' => Identity::stringify($row->id),
            'tenant_id' => Identity::stringify($row->tenant_id),
            'project_scope' => Identity::stringify($row->project_scope),
            'effective_subject' => Identity::stringify($row->effective_subject),
            'document_id' => Identity::stringify($row->document_id),
            'state' => Identity::stringify($row->state),
        ];
    }

    /**
     * Atomically acquire or reclaim a lease on a queued or expired-running job.
     * Uses random UUIDv4 for `lease_token` (never timestamp-leaking UUIDv7).
     */
    public function claimLease(string $jobId, int $leaseSeconds): ?string
    {
        if (! $this->lookup->isUuid($jobId)) {
            return null;
        }

        $token = (string) Str::uuid();

        return $this->database->transaction(function () use ($jobId, $token, $leaseSeconds): ?string {
            $conn = $this->lookup->connection();
            $row = $this->table($conn)->where('id', $jobId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                return null;
            }

            $state = Identity::stringify($row->state);
            if ($state !== ExchangeJobState::Queued->value && $state !== ExchangeJobState::Running->value) {
                return null;
            }

            $leaseUntil = is_string($row->lease_until ?? null) ? $row->lease_until : null;
            if ($state === ExchangeJobState::Running->value && $this->lookup->isFuture($leaseUntil, $conn)) {
                return null;
            }

            $now = $this->lookup->dbNow($conn);
            $until = $this->lookup->dbFutureTimestamp($leaseSeconds, $conn);

            $updated = $this->table($conn)
                ->where('id', $jobId)
                ->where('state', $state)
                ->update([
                    'state' => ExchangeJobState::Running->value,
                    'lease_token' => $token,
                    'lease_until' => $until,
                    'updated_at' => $now,
                ]);

            return $updated === 1 ? $token : null;
        });
    }

    /**
     * Fenced transition to `preview_ready` (import) or `completed` (export), pinning output artifact under lock.
     *
     * @param  array<string, mixed>  $diagnosticsReport
     */
    public function finalizeOutput(
        string $jobId,
        string $leaseToken,
        ExchangeJobState $targetState,
        string $outputBlobId,
        array $diagnosticsReport,
    ): ExchangeJobData {
        if ($targetState !== ExchangeJobState::PreviewReady && $targetState !== ExchangeJobState::Completed) {
            throw ExchangeException::invalidCommand('Invalid finalize target state.');
        }

        return $this->database->transaction(function () use (
            $jobId,
            $leaseToken,
            $targetState,
            $outputBlobId,
            $diagnosticsReport,
        ): ExchangeJobData {
            $conn = $this->lookup->connection();
            $row = $this->table($conn)->where('id', $jobId)->lockForUpdate()->first();
            $this->assertActiveLease($conn, $row, $leaseToken);

            // Lock order per BlobPins contract: owner row lock FIRST, then Media pin.
            $this->pins->pin($outputBlobId, self::OUTPUT_PIN_OWNER, $jobId);

            $now = $this->lookup->dbNow($conn);
            $encodedReport = json_encode((object) $diagnosticsReport, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $finishedAt = $targetState === ExchangeJobState::Completed ? $now : null;

            $affected = $this->table($conn)
                ->where('id', $jobId)
                ->where('state', ExchangeJobState::Running->value)
                ->where('lease_token', $leaseToken)
                ->update([
                    'state' => $targetState->value,
                    'output_blob_id' => $outputBlobId,
                    'diagnostics_report' => $encodedReport,
                    'error_code' => null,
                    'lease_token' => null,
                    'lease_until' => null,
                    'updated_at' => $now,
                    'finished_at' => $finishedAt,
                ]);

            if ($affected !== 1) {
                throw ExchangeException::leaseLost();
            }

            $fresh = $this->table($conn)->where('id', $jobId)->first();
            if (! $fresh instanceof stdClass) {
                throw ExchangeException::jobNotFound($jobId);
            }

            return $this->toData($fresh);
        });
    }

    /**
     * Fenced transition to terminal `failed` state with optional diagnostics report.
     *
     * @param  array<string, mixed>|null  $diagnosticsReport
     */
    public function markFailed(
        string $jobId,
        string $leaseToken,
        string $errorCode,
        ?array $diagnosticsReport = null,
    ): ExchangeJobData {
        return $this->database->transaction(function () use (
            $jobId,
            $leaseToken,
            $errorCode,
            $diagnosticsReport,
        ): ExchangeJobData {
            $conn = $this->lookup->connection();
            $row = $this->table($conn)->where('id', $jobId)->lockForUpdate()->first();
            $this->assertActiveLease($conn, $row, $leaseToken);

            $now = $this->lookup->dbNow($conn);
            $encodedReport = $diagnosticsReport !== null
                ? json_encode((object) $diagnosticsReport, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null;

            $affected = $this->table($conn)
                ->where('id', $jobId)
                ->where('state', ExchangeJobState::Running->value)
                ->where('lease_token', $leaseToken)
                ->update([
                    'state' => ExchangeJobState::Failed->value,
                    'error_code' => substr($errorCode, 0, 64),
                    'diagnostics_report' => $encodedReport,
                    'lease_token' => null,
                    'lease_until' => null,
                    'updated_at' => $now,
                    'finished_at' => $now,
                ]);

            if ($affected !== 1) {
                throw ExchangeException::leaseLost();
            }

            $fresh = $this->table($conn)->where('id', $jobId)->first();
            if (! $fresh instanceof stdClass) {
                throw ExchangeException::jobNotFound($jobId);
            }

            return $this->toData($fresh);
        });
    }

    public function releaseLeaseToQueued(string $jobId, string $leaseToken): void
    {
        $this->database->transaction(function () use ($jobId, $leaseToken): void {
            $conn = $this->lookup->connection();
            $now = $this->lookup->dbNow($conn);

            $this->table($conn)
                ->where('id', $jobId)
                ->where('state', ExchangeJobState::Running->value)
                ->where('lease_token', $leaseToken)
                ->update([
                    'state' => ExchangeJobState::Queued->value,
                    'lease_token' => null,
                    'lease_until' => null,
                    'updated_at' => $now,
                ]);
        });
    }

    /**
     * Lock one job row on an existing transaction connection (e.g., `LockedDocumentMutation::connection()`).
     */
    public function lockForApply(Connection $conn, string $jobId, int|string $documentId): ExchangeJobData
    {
        $row = $this->table($conn)
            ->where('id', $jobId)
            ->where('document_id', $documentId)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof stdClass) {
            throw ExchangeException::jobNotFound($jobId);
        }

        return $this->toData($row);
    }

    public function recordPendingJournalApply(Connection $conn, ExchangeJobData $locked, string $applyCommandId, ?string $commandRowId, int $slots): ExchangeJobData
    {
        if ($locked->applyCommandId === $applyCommandId) {
            return $locked;
        }

        $this->table($conn)
            ->where('id', $locked->id)
            ->update([
                'apply_command_id' => $applyCommandId,
                'reservation_command_id' => $commandRowId,
                'reserved_suggestions' => $slots,
                'updated_at' => $this->lookup->dbNow($conn),
            ]);

        return $this->findById($locked->id, $conn) ?? throw ExchangeException::jobNotFound($locked->id);
    }

    public function markAppliedOnConnection(
        Connection $conn,
        string $jobId,
        string $applyCommandId,
        string $applyReceiptId,
    ): ExchangeJobData {
        $now = $this->lookup->dbNow($conn);
        $affected = $this->table($conn)
            ->where('id', $jobId)
            ->where('kind', ExchangeJobKind::Import->value)
            ->where('state', ExchangeJobState::PreviewReady->value)
            ->update([
                'state' => ExchangeJobState::Applied->value,
                'apply_command_id' => $applyCommandId,
                'apply_receipt_id' => $applyReceiptId,
                'updated_at' => $now,
                'finished_at' => $now,
            ]);

        if ($affected !== 1) {
            throw ExchangeException::jobNotReady('apply_race');
        }

        $fresh = $this->table($conn)->where('id', $jobId)->first();
        if (! $fresh instanceof stdClass) {
            throw ExchangeException::jobNotFound($jobId);
        }

        return $this->toData($fresh);
    }

    /**
     * Reclaim expired workers and, with automatic dispatch, retry an old queued intent whose publication was lost.
     *
     * @param  (Closure(string, string, string, string): void)|null  $dispatch
     * @return list<string>
     */
    public function reclaimExpiredLeases(int $limit, int $retryAfterSeconds, ?Closure $dispatch): array
    {
        return $this->database->transaction(function () use ($limit, $retryAfterSeconds, $dispatch): array {
            $connection = $this->lookup->connection();
            $now = $this->lookup->dbNow($connection);
            $query = $this->table($connection)->where(static fn (Builder $running): Builder => $running
                ->where('state', ExchangeJobState::Running->value)
                ->where('lease_until', '<=', $now));
            if ($dispatch !== null) {
                $cutoff = $this->lookup->dbPastTimestamp($retryAfterSeconds, $connection);
                $query->orWhere(static fn (Builder $queued): Builder => $queued
                    ->where('state', ExchangeJobState::Queued->value)
                    ->where('updated_at', '<=', $cutoff));
            }

            $rows = $query->orderBy('updated_at')->orderBy('id')->limit($limit)->lockForUpdate()
                ->get(['id', 'tenant_id', 'project_scope', 'effective_subject']);
            $ids = [];
            foreach ($rows as $row) {
                $jobId = Identity::stringify($row->id);
                $ids[] = $jobId;
                if ($dispatch !== null) {
                    $tenant = Identity::stringify($row->tenant_id);
                    $scope = Identity::stringify($row->project_scope);
                    $subject = Identity::stringify($row->effective_subject);
                    $this->database->afterCommit(static fn () => $dispatch($jobId, $tenant, $scope, $subject));
                }
            }
            if ($ids !== []) {
                $this->table($connection)->whereIn('id', $ids)->update([
                    'state' => ExchangeJobState::Queued->value,
                    'lease_token' => null,
                    'lease_until' => null,
                    'updated_at' => $now,
                ]);
            }

            return $ids;
        });
    }

    /**
     * Purge expired terminal/stale exchange jobs and unpin their retained Media blobs inside one transaction.
     */
    public function purgeExpired(int $retentionSeconds, int $limit = 100): int
    {
        return $this->database->transaction(function () use ($retentionSeconds, $limit): int {
            $conn = $this->lookup->connection();
            $cutoff = $this->lookup->dbPastTimestamp($retentionSeconds, $conn);

            $candidates = $this->table($conn)
                ->whereIn('state', [
                    ExchangeJobState::Completed->value,
                    ExchangeJobState::Applied->value,
                    ExchangeJobState::Failed->value,
                    ExchangeJobState::PreviewReady->value,
                ])
                ->where('updated_at', '<=', $cutoff);
            $commands = $this->lookup->table('collaboration_commands');
            if ($conn->getSchemaBuilder()->hasTable($commands)) {
                $candidates->where(function (Builder $eligible) use ($commands): void {
                    $eligible->where('state', '!=', ExchangeJobState::PreviewReady->value)
                        ->orWhereNotExists(function (Builder $pending) use ($commands): void {
                            $pending->selectRaw('1')->from($commands.' as command')
                                ->whereColumn('command.id', $this->lookup->table('document_exchange_jobs').'.reservation_command_id')
                                ->whereIn('command.state', ['running', 'committed']);
                        });
                });
            }
            $rows = $candidates->orderBy('updated_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get(['id', 'source_blob_id', 'output_blob_id']);

            if ($rows->isEmpty()) {
                return 0;
            }

            $deleteIds = [];
            foreach ($rows as $row) {
                $jobId = Identity::stringify($row->id);
                $sourceBlobId = $row->source_blob_id !== null ? Identity::stringify($row->source_blob_id) : null;
                $outputBlobId = $row->output_blob_id !== null ? Identity::stringify($row->output_blob_id) : null;

                if ($sourceBlobId !== null && $sourceBlobId !== '') {
                    $this->pins->unpin($sourceBlobId, self::SOURCE_PIN_OWNER, $jobId);
                }
                if ($outputBlobId !== null && $outputBlobId !== '') {
                    $this->pins->unpin($outputBlobId, self::OUTPUT_PIN_OWNER, $jobId);
                }
                $deleteIds[] = $jobId;
            }

            if ($deleteIds === []) {
                return 0;
            }

            return $this->table($conn)->whereIn('id', $deleteIds)->delete();
        });
    }

    /**
     * Reconcile only this module's pins against the surviving owners in the purge transaction.
     * A worker's output pin and its owner row become visible together, so no pre-purge snapshot is needed.
     */
    public function releasePinsForDeletedJobs(Connection $conn): void
    {
        $references = $this->lookup->table('blob_references');
        $jobs = $this->lookup->table('document_exchange_jobs');

        foreach ([self::SOURCE_PIN_OWNER, self::OUTPUT_PIN_OWNER] as $owner) {
            $this->pins->releaseOwner($owner, static fn (): iterable => $conn
                ->table($references, 'exchange_pin')
                ->where('exchange_pin.owner_type', $owner)
                ->whereNotExists(static function (Builder $query) use ($jobs, $conn): void {
                    // reference_key is resolved from the outer pin query. Exchange keys are job UUIDs.
                    $query->from($jobs, 'exchange_job')
                        ->whereRaw(match ($conn->getDriverName()) {
                            'pgsql' => 'id = CAST(reference_key AS uuid)',
                            'mysql', 'mariadb' => 'id = CAST(reference_key AS CHAR(36))',
                            default => 'id = reference_key',
                        });
                })
                ->distinct()
                ->select('exchange_pin.reference_key')
                ->lazyById(column: 'exchange_pin.reference_key', alias: 'reference_key')
                ->map(static fn (stdClass $row): string => Identity::stringify($row->reference_key)));
        }
    }

    private function assertActiveLease(Connection $conn, mixed $row, string $leaseToken): void
    {
        if (! $row instanceof stdClass) {
            throw ExchangeException::leaseLost();
        }
        if (Identity::stringify($row->state) !== ExchangeJobState::Running->value
            || Identity::stringify($row->lease_token) !== $leaseToken) {
            throw ExchangeException::leaseLost();
        }
        $leaseUntil = is_string($row->lease_until ?? null) ? $row->lease_until : null;
        if (! $this->lookup->isFuture($leaseUntil, $conn)) {
            throw ExchangeException::leaseLost();
        }
    }

    private function replayExisting(stdClass $existing, string $fingerprintHex): ExchangeJobData
    {
        $storedHex = $this->lookup->decodeFingerprintHex($existing->request_fingerprint ?? null);
        if (! hash_equals($storedHex, strtolower($fingerprintHex))) {
            throw ExchangeException::idempotencyConflict();
        }

        return $this->toData($existing);
    }

    private function table(?Connection $connection = null): Builder
    {
        return ($connection ?? $this->lookup->connection())
            ->table($this->lookup->table('document_exchange_jobs'));
    }

    private function toData(stdClass $row): ExchangeJobData
    {
        $report = null;
        if (is_string($row->diagnostics_report ?? null) && $row->diagnostics_report !== '') {
            try {
                $decoded = json_decode($row->diagnostics_report, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $out = [];
                    foreach ($decoded as $k => $v) {
                        $out[(string) $k] = $v;
                    }
                    $report = $out;
                }
            } catch (JsonException) {
                $report = null;
            }
        }

        return new ExchangeJobData(
            id: Identity::stringify($row->id),
            documentId: Identity::stringify($row->document_id),
            requestKey: Identity::stringify($row->request_key),
            kind: ExchangeJobKind::from(Identity::stringify($row->kind)),
            format: ExchangeFormat::from(Identity::stringify($row->format)),
            profileId: Identity::stringify($row->profile_id),
            state: ExchangeJobState::from(Identity::stringify($row->state)),
            sourceBlobId: $row->source_blob_id !== null ? Identity::stringify($row->source_blob_id) : null,
            sourceRevisionId: $row->source_revision_id !== null ? Identity::stringify($row->source_revision_id) : null,
            sourceGeneration: is_numeric($row->source_generation ?? null) ? (int) $row->source_generation : null,
            sourcePosition: is_numeric($row->source_position ?? null) ? (int) $row->source_position : null,
            outputBlobId: $row->output_blob_id !== null ? Identity::stringify($row->output_blob_id) : null,
            applyCommandId: $row->apply_command_id !== null ? Identity::stringify($row->apply_command_id) : null,
            applyReceiptId: $row->apply_receipt_id !== null ? Identity::stringify($row->apply_receipt_id) : null,
            diagnosticsReport: $report,
            errorCode: $row->error_code !== null ? Identity::stringify($row->error_code) : null,
            createdAt: CarbonImmutable::parse(Identity::stringify($row->created_at), 'UTC'),
            updatedAt: CarbonImmutable::parse(Identity::stringify($row->updated_at), 'UTC'),
            finishedAt: $row->finished_at !== null ? CarbonImmutable::parse(Identity::stringify($row->finished_at), 'UTC') : null,
        );
    }
}
