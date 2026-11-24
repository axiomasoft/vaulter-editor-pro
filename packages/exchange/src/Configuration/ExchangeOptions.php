<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;

/**
 * Validated runtime configuration options for `vaulter-editor-pro-exchange`.
 */
#[Api(since: '1.0')]
final readonly class ExchangeOptions implements OptionsFactory
{
    /**
     * @param  list<string>  $formats
     * @param  list<string>  $profiles
     * @param  int<1, max>  $maxImportBytes
     * @param  int<1, max>  $maxExportBytes
     * @param  int<1, max>  $zipMaxEntries
     * @param  int<1, max>  $zipMaxEntryBytes
     * @param  int<1, max>  $zipMaxTotalBytes
     * @param  int<1, max>  $zipMaxRatio
     * @param  int<1, max>  $zipMaxRelsBytes
     * @param  int<1, max>  $leaseSeconds
     * @param  int<1, max>  $jobRetentionSeconds
     * @param  int<1, max>  $runtimeTimeoutSeconds
     */
    public function __construct(
        public array $formats = ['docx'],
        public string $defaultProfile = 'discussions',
        public array $profiles = ['standard', 'discussions', 'legacy-discussions', 'revisions', 'strict-ooxml'],
        public int $maxImportBytes = 50 * 1024 * 1024,
        public int $maxExportBytes = 50 * 1024 * 1024,
        public int $zipMaxEntries = 2_000,
        public int $zipMaxEntryBytes = 50 * 1024 * 1024,
        public int $zipMaxTotalBytes = 150 * 1024 * 1024,
        public int $zipMaxRatio = 100,
        public int $zipMaxRelsBytes = 256 * 1024,
        public int $leaseSeconds = 60,
        public int $jobRetentionSeconds = 7 * 86_400,
        public bool $autoDispatch = true,
        public ?string $queueConnection = null,
        public string $queueName = 'default',
        public string $nodeBinary = 'node',
        public string $cliPath = '',
        public int $runtimeTimeoutSeconds = 30,
        public bool $persistentWorker = false,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config, string $path = 'vaulter-editor-pro-exchange'): self
    {
        $rawFormats = is_array($config['formats'] ?? null) ? $config['formats'] : ['docx'];
        $formats = [];
        foreach ($rawFormats as $item) {
            if (is_string($item) && trim($item) !== '') {
                $norm = strtolower(trim($item));
                if (! in_array($norm, $formats, true)) {
                    $formats[] = $norm;
                }
            }
        }

        $rawProfiles = is_array($config['profiles'] ?? null)
            ? $config['profiles']
            : ['standard', 'discussions', 'legacy-discussions', 'revisions', 'strict-ooxml'];
        $profiles = [];
        foreach ($rawProfiles as $item) {
            if (is_string($item) && trim($item) !== '') {
                $norm = trim($item);
                if (! in_array($norm, $profiles, true)) {
                    $profiles[] = $norm;
                }
            }
        }
        if ($profiles === []) {
            $profiles = ['discussions'];
        }

        $defaultProfile = is_string($config['default_profile'] ?? null) && $config['default_profile'] !== ''
            ? trim($config['default_profile'])
            : 'discussions';
        $zip = is_array($config['zip'] ?? null) ? $config['zip'] : [];

        $defaultCli = dirname(__DIR__, 3).'/docx/dist/cli.js';
        $cliPath = is_string($config['cli_path'] ?? null) && trim($config['cli_path']) !== ''
            ? trim($config['cli_path'])
            : $defaultCli;
        $nodeBinary = is_string($config['node_binary'] ?? null) && trim($config['node_binary']) !== ''
            ? trim($config['node_binary'])
            : 'node';
        $queueConn = is_string($config['queue_connection'] ?? null) && trim($config['queue_connection']) !== ''
            ? trim($config['queue_connection'])
            : null;
        $queueName = is_string($config['queue_name'] ?? null) && trim($config['queue_name']) !== ''
            ? trim($config['queue_name'])
            : 'default';

        $rawPersistent = $config['persistent_worker'] ?? false;
        $persistentWorker = is_bool($rawPersistent)
            ? $rawPersistent
            : (is_string($rawPersistent) && in_array(strtolower(trim($rawPersistent)), ['1', 'true', 'yes', 'on'], true))
            || (is_int($rawPersistent) && $rawPersistent === 1);

        return new self(
            formats: $formats,
            defaultProfile: $defaultProfile,
            profiles: $profiles,
            maxImportBytes: self::positiveInt($config['max_import_bytes'] ?? null, 50 * 1024 * 1024),
            maxExportBytes: self::positiveInt($config['max_export_bytes'] ?? null, 50 * 1024 * 1024),
            zipMaxEntries: self::positiveInt($zip['max_entries'] ?? null, 2_000),
            zipMaxEntryBytes: self::positiveInt($zip['max_entry_bytes'] ?? null, 50 * 1024 * 1024),
            zipMaxTotalBytes: self::positiveInt($zip['max_total_bytes'] ?? null, 150 * 1024 * 1024),
            zipMaxRatio: self::positiveInt($zip['max_ratio'] ?? null, 100),
            zipMaxRelsBytes: self::positiveInt($zip['max_rels_bytes'] ?? null, 256 * 1024),
            leaseSeconds: self::positiveInt($config['lease_seconds'] ?? null, 60),
            jobRetentionSeconds: self::positiveInt($config['job_retention_seconds'] ?? null, 7 * 86_400),
            autoDispatch: (bool) ($config['auto_dispatch'] ?? true),
            queueConnection: $queueConn,
            queueName: $queueName,
            nodeBinary: $nodeBinary,
            cliPath: $cliPath,
            runtimeTimeoutSeconds: self::positiveInt($config['runtime_timeout_seconds'] ?? null, 30),
            persistentWorker: $persistentWorker,
        );
    }

    /** @return int<1, max> */
    private static function positiveInt(mixed $value, int $default): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return max(1, $default);
    }
}
