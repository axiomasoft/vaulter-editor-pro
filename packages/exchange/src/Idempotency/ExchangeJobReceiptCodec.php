<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Idempotency;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Exchange\Data\ExchangeJobData;
use Vaulter\EditorPro\Exchange\Enums\ExchangeFormat;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobKind;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/** Encodes/decodes an `ExchangeJobData` registration receipt for `vaulter.idempotency`. */
#[Internal]
final class ExchangeJobReceiptCodec implements ReceiptCodec
{
    public const string KIND = 'exchange.job';

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(private readonly string $nodeId) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function version(): int
    {
        return 1;
    }

    /** @return array<string, mixed> */
    public function encode(mixed $result): array
    {
        if (is_array($result) && ($result['job'] ?? null) instanceof ExchangeJobData) {
            $created = (bool) ($result['created'] ?? false);
            $job = $result['job'];
        } elseif ($result instanceof ExchangeJobData) {
            $created = false;
            $job = $result;
        } else {
            throw new InvalidArgumentException(sprintf('[Vaulter Exchange] %s expects ExchangeJobData, %s given.', self::KIND, get_debug_type($result)));
        }

        return [
            'created' => $created,
            'job' => [
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
                'created_at' => $job->createdAt->format(self::TIME_FORMAT),
                'updated_at' => $job->updatedAt->format(self::TIME_FORMAT),
                'finished_at' => $job->finishedAt?->format(self::TIME_FORMAT),
            ],
        ];
    }

    /** @return array{created: bool, job: ExchangeJobData} */
    public function decode(array $body, int $version): array
    {
        if ($version !== 1) {
            throw new LogicException(sprintf('[Vaulter Exchange] Unknown receipt version %d.', $version));
        }

        $j = $body['job'] ?? null;
        if (! is_array($j)) {
            throw new LogicException('[Vaulter Exchange] Missing job payload in receipt.');
        }

        /** @var array<string, mixed>|null $report */
        $report = is_array($j['diagnostics_report'] ?? null) ? $j['diagnostics_report'] : null;

        return [
            'created' => false,
            'job' => new ExchangeJobData(
                id: self::reqStr($j, 'id'),
                documentId: self::reqStr($j, 'document_id'),
                requestKey: self::reqStr($j, 'request_key'),
                kind: ExchangeJobKind::from(self::reqStr($j, 'kind')),
                format: ExchangeFormat::from(self::reqStr($j, 'format')),
                profileId: self::reqStr($j, 'profile_id'),
                state: ExchangeJobState::from(self::reqStr($j, 'state')),
                sourceBlobId: self::optStr($j['source_blob_id'] ?? null),
                sourceRevisionId: self::optStr($j['source_revision_id'] ?? null),
                sourceGeneration: self::optInt($j['source_generation'] ?? null),
                sourcePosition: self::optInt($j['source_position'] ?? null),
                outputBlobId: self::optStr($j['output_blob_id'] ?? null),
                applyCommandId: self::optStr($j['apply_command_id'] ?? null),
                applyReceiptId: self::optStr($j['apply_receipt_id'] ?? null),
                diagnosticsReport: $report,
                errorCode: self::optStr($j['error_code'] ?? null),
                createdAt: CarbonImmutable::parse(self::reqStr($j, 'created_at'), 'UTC'),
                updatedAt: CarbonImmutable::parse(self::reqStr($j, 'updated_at'), 'UTC'),
                finishedAt: ($f = self::optStr($j['finished_at'] ?? null)) !== null ? CarbonImmutable::parse($f, 'UTC') : null,
            ),
        ];
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('node', $this->nodeId);
    }

    /** @param array<array-key, mixed> $fields */
    private static function reqStr(array $fields, string $key): string
    {
        $val = $fields[$key] ?? null;
        if (! is_string($val)) {
            throw new LogicException(sprintf('[Vaulter Exchange] Receipt missing string "%s".', $key));
        }

        return $val;
    }

    private static function optStr(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function optInt(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }
}
