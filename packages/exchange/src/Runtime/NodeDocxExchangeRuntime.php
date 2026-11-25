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
 * Executes `@vaulter/editor-pro-docx` in an isolated Node subprocess using temp-file binary
 * transfer (`0600`) and optional persistent `--persistent` NDJSON worker mode.
 */
#[Internal]
final class NodeDocxExchangeRuntime implements DocxExchangeRuntime
{
    /** @var resource|null */
    private mixed $workerProcess = null;

    /** @var array{0: resource, 1: resource, 2: resource}|null */
    private ?array $workerPipes = null;

    public function __construct(private readonly ExchangeOptions $options) {}

    public function __destruct()
    {
        $this->shutdown();
    }

    public function shutdown(): void
    {
        if ($this->workerPipes !== null) {
            foreach ($this->workerPipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            $this->workerPipes = null;
        }
        if (is_resource($this->workerProcess)) {
            $status = proc_get_status($this->workerProcess);
            if ($status['running']) {
                @proc_terminate($this->workerProcess);
            }
            @proc_close($this->workerProcess);
        }
        $this->workerProcess = null;
    }

    public function importDocx(
        string $docxBytes,
        string $profileId,
        ?array $sourceMarker = null,
        array $options = [],
    ): ExchangeRuntimeImportResult {
        $requestId = (string) Str::uuid();
        $inputPath = $this->createTempFile('vedx-in-');

        try {
            $written = @file_put_contents($inputPath, $docxBytes);
            if ($written !== strlen($docxBytes)) {
                throw ExchangeException::runtimeFailed('temp_file_failed', 'Failed to write temporary DOCX input file.');
            }

            $effectiveOptions = $options + ['stripSectionAndReviewMetadata' => true];
            $payload = [
                'version' => 1,
                'request_id' => $requestId,
                'operation' => 'import',
                'profile_id' => $profileId,
                'docx_path' => $inputPath,
                'quotas' => [
                    'maxEntries' => $this->options->zipMaxEntries,
                    'maxFileBytes' => $this->options->zipMaxEntryBytes,
                    'maxTotalBytes' => $this->options->zipMaxTotalBytes,
                    'maxCompressionRatio' => $this->options->zipMaxRatio,
                ],
                ...($sourceMarker !== null ? ['source_marker' => $sourceMarker] : []),
                'options' => $effectiveOptions,
            ];

            $response = $this->invoke($payload, $requestId);
        } finally {
            @unlink($inputPath);
        }

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
            sections: self::listOfStringMaps($response['sections'] ?? null),
            discussions: self::listOfStringMaps($response['discussions'] ?? null),
            revisions: self::listOfStringMaps($response['revisions'] ?? null),
        );
    }

    public function exportDocx(
        array $document,
        string $profileId,
        ?array $sourceMarker = null,
        array $discussions = [],
        array $options = [],
        array $sections = [],
        array $revisions = [],
    ): ExchangeRuntimeExportResult {
        $requestId = (string) Str::uuid();
        $projection = is_string($options['projection'] ?? null) ? $options['projection'] : null;
        $title = is_string($options['title'] ?? null) ? $options['title'] : null;
        $creator = is_string($options['creator'] ?? null) ? $options['creator'] : null;

        $outputPath = $this->createTempFile('vedx-out-');

        try {
            $payload = [
                'version' => 1,
                'request_id' => $requestId,
                'operation' => 'export',
                'profile_id' => $profileId,
                'output_path' => $outputPath,
                'document' => $document,
                ...($projection !== null ? ['projection' => $projection] : []),
                ...($sourceMarker !== null ? ['source_marker' => $sourceMarker] : []),
                ...($sections !== [] ? ['sections' => $sections] : []),
                ...($discussions !== [] ? ['discussions' => $discussions] : []),
                ...($revisions !== [] ? ['revisions' => $revisions] : []),
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

            $returnedPath = is_string($response['docx_path'] ?? null) ? $response['docx_path'] : '';
            $expectedSha = is_string($response['docx_sha256'] ?? null) ? $response['docx_sha256'] : '';
            if ($returnedPath !== $outputPath || preg_match('/\A[0-9a-f]{64}\z/', $expectedSha) !== 1 || ! is_array($rawReport)) {
                throw ExchangeException::runtimeFailed('malformed_export_response');
            }

            clearstatcache(true, $outputPath);
            $size = @filesize($outputPath);
            if (! is_int($size) || $size <= 0) {
                throw ExchangeException::runtimeFailed('malformed_export_response');
            }
            if ($size > $this->options->maxExportBytes) {
                throw ExchangeException::zipLimitExceeded('export_size_limit');
            }

            $bytes = @file_get_contents($outputPath);
            if (! is_string($bytes) || ! hash_equals($expectedSha, hash('sha256', $bytes))) {
                throw ExchangeException::runtimeFailed('checksum_mismatch');
            }

            return new ExchangeRuntimeExportResult(
                blocked: false,
                docxBytes: $bytes,
                docxSha256: $expectedSha,
                report: self::stringKeyMap($rawReport),
                semantic: is_array($rawSemantic) ? self::stringKeyMap($rawSemantic) : null,
            );
        } finally {
            @unlink($outputPath);
        }
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

        if ($this->options->persistentWorker) {
            $stdout = $this->invokePersistent($stdin."\n");
        } else {
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
                'zip_quota_exceeded', 'zip_bomb_ratio', 'limit_exceeded', 'media_quota_exceeded' => ExchangeException::zipLimitExceeded($code),
                'zip_invalid_archive',
                'zip_slip',
                'zip_symlink',
                'zip_special_file',
                'zip_duplicate_entry',
                'zip_overlapping_ranges',
                'xml_xxe_forbidden',
                'xml_malformed',
                'xml_depth_exceeded',
                'external_rel_forbidden',
                'media_invalid_format',
                'media_svg_unsafe' => ExchangeException::unsafeArchive($code, detailMessage: $message),
                'profile_blocked' => ExchangeException::profileBlocked($code),
                'invalid_request', 'invalid_profile' => ExchangeException::invalidCommand($message, $code),
                default => ExchangeException::runtimeFailed($code, $message),
            };
        }

        return self::stringKeyMap($decoded);
    }

    private function createTempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw ExchangeException::runtimeFailed('temp_file_failed', 'Unable to allocate temporary DOCX file.');
        }
        if (! @chmod($path, 0o600)) {
            @unlink($path);
            throw ExchangeException::runtimeFailed('temp_file_failed', 'Unable to set permissions on temporary DOCX file.');
        }

        return $path;
    }

    private function invokePersistent(string $line): string
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reused = is_resource($this->workerProcess);
            $pipes = $this->ensurePersistentWorker();
            $written = @fwrite($pipes[0], $line);
            @fflush($pipes[0]);
            if ($written !== strlen($line)) {
                $this->shutdown();
                if ($reused && $attempt === 0) {
                    continue;
                }
                throw ExchangeException::runtimeFailed('process_failed', 'Failed to write request to persistent DOCX worker.');
            }

            $deadline = microtime(true) + (float) $this->options->runtimeTimeoutSeconds;
            $buffer = '';
            while (true) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0.0) {
                    $this->shutdown();
                    throw ExchangeException::runtimeFailed('process_timeout', 'Persistent DOCX worker timed out.');
                }

                $read = [$pipes[1]];
                $write = null;
                $except = null;
                $sec = (int) floor($remaining);
                $usec = (int) (($remaining - $sec) * 1_000_000);
                $ready = @stream_select($read, $write, $except, $sec, $usec);
                if ($ready === false) {
                    $this->shutdown();
                    throw ExchangeException::runtimeFailed('process_failed', 'stream_select failed on persistent DOCX worker.');
                }
                if ($ready === 0) {
                    $this->shutdown();
                    throw ExchangeException::runtimeFailed('process_timeout', 'Persistent DOCX worker timed out.');
                }

                $chunk = @fgets($pipes[1]);
                if ($chunk === false) {
                    $stderr = @stream_get_contents($pipes[2]);
                    $this->shutdown();
                    if ($reused && $attempt === 0) {
                        break;
                    }
                    $detail = is_string($stderr) && trim($stderr) !== '' ? trim($stderr) : 'Persistent DOCX worker exited unexpectedly.';
                    throw ExchangeException::runtimeFailed('process_failed', $detail);
                }

                $buffer .= $chunk;
                if (str_ends_with($buffer, "\n")) {
                    $trimmed = trim($buffer);
                    if ($trimmed !== '') {
                        return $trimmed;
                    }
                    $buffer = '';
                }
            }
        }

        throw ExchangeException::runtimeFailed('process_failed', 'Persistent DOCX worker failed.');
    }

    /** @return array{0: resource, 1: resource, 2: resource} */
    private function ensurePersistentWorker(): array
    {
        if (is_resource($this->workerProcess) && $this->workerPipes !== null) {
            $status = proc_get_status($this->workerProcess);
            if ($status['running']) {
                return $this->workerPipes;
            }
            $this->shutdown();
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open(
            [$this->options->nodeBinary, $this->options->cliPath, '--persistent'],
            $descriptors,
            $pipes,
        );
        if (! is_resource($process)
            || ! isset($pipes[0], $pipes[1], $pipes[2])
            || ! is_resource($pipes[0])
            || ! is_resource($pipes[1])
            || ! is_resource($pipes[2])) {
            if (is_resource($process)) {
                @proc_close($process);
            }
            throw ExchangeException::runtimeFailed('process_failed', 'Unable to start persistent DOCX worker.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->workerProcess = $process;
        $this->workerPipes = [$pipes[0], $pipes[1], $pipes[2]];

        return $this->workerPipes;
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

    /** @return list<array<string, mixed>> */
    private static function listOfStringMaps(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $out[] = self::stringKeyMap($item);
            }
        }

        return $out;
    }
}
