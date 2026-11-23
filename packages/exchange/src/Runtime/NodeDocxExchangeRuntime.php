<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Runtime;

use Illuminate\Support\Str;
use JsonException;
use Packman\Attributes\Internal;
use Symfony\Component\Process\Process;
use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
use Vaulter\EditorPro\Exchange\Contracts\DocxExchangeRuntime;
use Vaulter\EditorPro\Exchange\Data\ExchangeRuntimeExportResult;
use Vaulter\EditorPro\Exchange\Data\ExchangeRuntimeImportResult;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;

/**
 * Executes `@vaulter/editor-pro-docx` in an isolated Node subprocess over bounded stdin/stdout JSON.
 */
#[Internal]
final readonly class NodeDocxExchangeRuntime implements DocxExchangeRuntime
{
    public function __construct(private ExchangeOptions $options) {}

    public function importDocx(
        string $docxBytes,
        string $profileId,
        ?array $sourceMarker = null,
        array $options = [],
    ): ExchangeRuntimeImportResult {
        $requestId = (string) Str::uuid();
        $payload = [
            'version' => 1,
            'request_id' => $requestId,
            'operation' => 'import',
            'profile_id' => $profileId,
            'docx_b64' => base64_encode($docxBytes),
            'quotas' => [
                'maxEntries' => $this->options->zipMaxEntries,
                'maxFileBytes' => $this->options->zipMaxEntryBytes,
                'maxTotalBytes' => $this->options->zipMaxTotalBytes,
                'maxCompressionRatio' => $this->options->zipMaxRatio,
            ],
            ...($sourceMarker !== null ? ['source_marker' => $sourceMarker] : []),
            ...($options !== [] ? ['options' => $options] : []),
        ];

        $response = $this->invoke($payload, $requestId);

        $rawDoc = $response['document'] ?? null;
        $rawReport = $response['report'] ?? null;
        if (! is_array($rawDoc) || ! is_array($rawReport)) {
            throw ExchangeException::runtimeFailed('malformed_import_response');
        }

        /** @var array<string, mixed> $docMap */
        $docMap = self::stringKeyMap($rawDoc);
        /** @var array<string, mixed> $reportMap */
        $reportMap = self::stringKeyMap($rawReport);
        $rawSemantic = $response['semantic'] ?? null;

        return new ExchangeRuntimeImportResult(
            blocked: (bool) ($response['blocked'] ?? false),
            sourceSha256: is_string($response['source_sha256'] ?? null)
                ? $response['source_sha256']
                : hash('sha256', $docxBytes),
            document: $docMap,
            report: $reportMap,
            semantic: is_array($rawSemantic) ? self::stringKeyMap($rawSemantic) : null,
        );
    }

    public function exportDocx(
        array $document,
        string $profileId,
        ?array $sourceMarker = null,
        array $discussions = [],
        array $options = [],
    ): ExchangeRuntimeExportResult {
        $requestId = (string) Str::uuid();
        $projection = is_string($options['projection'] ?? null) ? $options['projection'] : null;
        $title = is_string($options['title'] ?? null) ? $options['title'] : null;
        $creator = is_string($options['creator'] ?? null) ? $options['creator'] : null;

        $payload = [
            'version' => 1,
            'request_id' => $requestId,
            'operation' => 'export',
            'profile_id' => $profileId,
            'document' => $document,
            ...($projection !== null ? ['projection' => $projection] : []),
            ...($sourceMarker !== null ? ['source_marker' => $sourceMarker] : []),
            ...($discussions !== [] ? ['discussions' => $discussions] : []),
            ...($title !== null ? ['title' => $title] : []),
            ...($creator !== null ? ['creator' => $creator] : []),
            ...($options !== [] ? ['options' => $options] : []),
        ];

        try {
            $response = $this->invoke($payload, $requestId);
        } catch (ExchangeException $blocked) {
            if ($blocked->problemCode() === 'exchange_profile_blocked') {
                return new ExchangeRuntimeExportResult(
                    blocked: true,
                    docxBytes: null,
                    docxSha256: null,
                    report: ['blocked' => true, 'blocking_reasons' => [$blocked->getMessage()]],
                    semantic: null,
                );
            }
            throw $blocked;
        }

        $rawReport = $response['report'] ?? null;
        $rawSemantic = $response['semantic'] ?? null;
        if ((bool) ($response['blocked'] ?? false)) {
            return new ExchangeRuntimeExportResult(
                blocked: true,
                docxBytes: null,
                docxSha256: null,
                report: is_array($rawReport) ? self::stringKeyMap($rawReport) : ['blocked' => true],
                semantic: is_array($rawSemantic) ? self::stringKeyMap($rawSemantic) : null,
            );
        }

        $b64 = is_string($response['docx_b64'] ?? null) ? $response['docx_b64'] : '';
        $expectedSha = is_string($response['docx_sha256'] ?? null) ? $response['docx_sha256'] : '';
        if ($b64 === '' || preg_match('/\A[0-9a-f]{64}\z/', $expectedSha) !== 1 || ! is_array($rawReport)) {
            throw ExchangeException::runtimeFailed('malformed_export_response');
        }

        $bytes = base64_decode($b64, true);
        if (! is_string($bytes) || ! hash_equals($expectedSha, hash('sha256', $bytes))) {
            throw ExchangeException::runtimeFailed('checksum_mismatch');
        }
        if (strlen($bytes) > $this->options->maxExportBytes) {
            throw ExchangeException::zipLimitExceeded('export_size_limit');
        }

        return new ExchangeRuntimeExportResult(
            blocked: false,
            docxBytes: $bytes,
            docxSha256: $expectedSha,
            report: self::stringKeyMap($rawReport),
            semantic: is_array($rawSemantic) ? self::stringKeyMap($rawSemantic) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function invoke(array $payload, string $requestId): array
    {
        if (! is_file($this->options->cliPath)) {
            throw ExchangeException::runtimeFailed('cli_missing', 'DOCX CLI binary not found at configured path.');
        }

        try {
            $stdin = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw ExchangeException::invalidCommand('Failed to serialize exchange runtime request.', previous: $e);
        }

        $process = new Process([$this->options->nodeBinary, $this->options->cliPath]);
        $process->setInput($stdin."\n");
        $process->setTimeout((float) $this->options->runtimeTimeoutSeconds);
        $process->run();

        $stdout = trim($process->getOutput());
        if ($stdout === '') {
            $stderr = trim($process->getErrorOutput());
            throw ExchangeException::runtimeFailed(
                'process_failed',
                $stderr !== '' ? $stderr : 'DOCX runtime exited with empty output (code '.$process->getExitCode().').',
            );
        }

        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ExchangeException::runtimeFailed('invalid_json_output', 'DOCX runtime emitted invalid JSON.', $e);
        }

        if (! is_array($decoded)
            || ($decoded['version'] ?? null) !== 1
            || ($decoded['request_id'] ?? null) !== $requestId
            || ! is_bool($decoded['ok'] ?? null)) {
            throw ExchangeException::runtimeFailed('invalid_envelope');
        }

        if ($decoded['ok'] === false) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $code = is_string($error['code'] ?? null) ? $error['code'] : 'runtime_failed';
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'DOCX conversion failed.';

            throw match ($code) {
                'zip_quota_exceeded', 'zip_bomb_ratio', 'limit_exceeded' => ExchangeException::zipLimitExceeded($code),
                'zip_invalid_archive',
                'zip_slip',
                'zip_symlink',
                'zip_special_file',
                'zip_duplicate_entry',
                'zip_overlapping_ranges',
                'xml_xxe_forbidden',
                'xml_malformed',
                'xml_depth_exceeded',
                'external_rel_forbidden' => ExchangeException::unsafeArchive($code, detailMessage: $message),
                'profile_blocked' => ExchangeException::profileBlocked($code),
                'invalid_request', 'invalid_profile' => ExchangeException::invalidCommand($message, $code),
                default => ExchangeException::runtimeFailed($code, $message),
            };
        }

        return self::stringKeyMap($decoded);
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function stringKeyMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
