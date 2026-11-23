<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Packman\Attributes\Internal;
use stdClass;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\Support\Identity;

/**
 * Parameterized lookup, clock, binary fingerprint codec, and document metadata helper
 * using the owner `VaulterDatabase` connection.
 */
#[Internal]
final readonly class ExchangeLookup
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseOptions $dbOptions,
        private IdsOptions $idsOptions,
    ) {}

    public function connection(): Connection
    {
        return $this->database->connection();
    }

    public function table(string $entity): string
    {
        return $this->dbOptions->table($entity);
    }

    /**
     * @return array{id: int|string, node_id: int|string, tenant_id: int|string, type: string, collab_epoch: int, state_version: int, schema_version: int, write_mode: string}
     */
    public function documentForNode(int|string $nodeId): array
    {
        if (! $this->fitsKey($nodeId)) {
            throw ExchangeException::unsupportedDocument('invalid_node_key');
        }

        $row = $this->connection()
            ->table($this->table('documents'))
            ->where('node_id', $nodeId)
            ->first(['id', 'node_id', 'tenant_id', 'type', 'collab_epoch', 'state_version', 'schema_version', 'write_mode']);

        if (! $row instanceof stdClass) {
            throw ExchangeException::unsupportedDocument('target_not_document');
        }

        /** @var int|string|null $rawDocId */
        $rawDocId = $row->id ?? null;
        if (! is_int($rawDocId) && ! is_string($rawDocId)) {
            throw ExchangeException::unsupportedDocument('missing_document_id');
        }

        /** @var int|string|null $rawTenantId */
        $rawTenantId = $row->tenant_id ?? null;

        return [
            'id' => $rawDocId,
            'node_id' => $nodeId,
            'tenant_id' => $this->normalizeTenantId($rawTenantId),
            'type' => is_string($row->type ?? null) ? $row->type : '',
            'collab_epoch' => is_numeric($row->collab_epoch ?? null) ? (int) $row->collab_epoch : 0,
            'state_version' => is_numeric($row->state_version ?? null) ? (int) $row->state_version : 0,
            'schema_version' => is_numeric($row->schema_version ?? null) ? (int) $row->schema_version : 1,
            'write_mode' => is_string($row->write_mode ?? null) ? $row->write_mode : 'snapshot',
        ];
    }

    /**
     * Resolve `node_id` for a document UUID/key (used by background workers).
     */
    public function nodeIdForDocument(int|string $documentId): ?string
    {
        if (! $this->fitsKey($documentId)) {
            return null;
        }

        $value = $this->connection()
            ->table($this->table('documents'))
            ->where('id', $documentId)
            ->value('node_id');

        return is_int($value) || is_string($value) ? (string) $value : null;
    }

    public function fitsKey(int|string $value): bool
    {
        $raw = (string) $value;
        if ($raw === '') {
            return false;
        }

        return match ($this->idsOptions->strategy) {
            'bigint' => preg_match('/^[0-9]+$/', $raw) === 1,
            'uuid7' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw) === 1,
            'ulid' => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $raw) === 1,
            default => true,
        };
    }

    public function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1;
    }

    public function normalizeTenantId(int|string|null $tenantId): int|string
    {
        $key = Identity::key($tenantId);
        if ($key !== null && $key !== '') {
            return $key;
        }

        return match ($this->idsOptions->hostKeys) {
            'bigint' => 0,
            'ulid' => str_repeat('0', 26),
            default => '00000000-0000-0000-0000-000000000000',
        };
    }

    public static function projectScope(DriveContext $context): string
    {
        return $context->operation->scope->kind.':'.$context->operation->scope->id;
    }

    public static function effectiveSubject(DriveContext $context): string
    {
        $ref = $context->actor->ref();
        $idPart = $ref->id !== null ? (string) $ref->id : ($ref->reason ?? 'system');

        $subject = $ref->type.':'.$idPart;

        return strlen($subject) > 255 ? substr($subject, 0, 255) : $subject;
    }

    public function encodeFingerprintHex(string $hex, ?Connection $connection = null): string
    {
        $driver = ($connection ?? $this->connection())->getDriverName();
        $norm = strtolower($hex);
        if ($driver === 'pgsql') {
            return '\\x'.$norm;
        }

        $bin = hex2bin($norm);

        return $bin !== false ? $bin : str_repeat("\0", 32);
    }

    public function decodeFingerprintHex(mixed $raw): string
    {
        if (is_resource($raw)) {
            $read = stream_get_contents($raw);
            $raw = is_string($read) ? $read : '';
        }
        if (! is_string($raw)) {
            return '';
        }
        if (strlen($raw) === 32) {
            return bin2hex($raw);
        }
        if (str_starts_with($raw, '\\x') && strlen($raw) === 66) {
            return strtolower(substr($raw, 2));
        }
        if (strlen($raw) === 64 && ctype_xdigit($raw)) {
            return strtolower($raw);
        }

        return bin2hex($raw);
    }

    /**
     * Database-consistent current timestamp (`clock_timestamp()` on PostgreSQL when not frozen in Carbon tests).
     */
    public function dbNow(?Connection $connection = null): string
    {
        $conn = $connection ?? $this->connection();
        if ($conn->getDriverName() === 'pgsql' && ! Carbon::hasTestNow()) {
            $row = $conn->selectOne('SELECT clock_timestamp()::text AS ts');
            if ($row instanceof stdClass && is_string($row->ts ?? null)) {
                return $row->ts;
            }
        }

        return Carbon::now('UTC')->format('Y-m-d H:i:s.uP');
    }

    public function dbFutureTimestamp(int $seconds, ?Connection $connection = null): string
    {
        $conn = $connection ?? $this->connection();
        if ($conn->getDriverName() === 'pgsql' && ! Carbon::hasTestNow()) {
            $row = $conn->selectOne('SELECT (clock_timestamp() + make_interval(secs => ?))::text AS ts', [$seconds]);
            if ($row instanceof stdClass && is_string($row->ts ?? null)) {
                return $row->ts;
            }
        }

        return Carbon::now('UTC')->addSeconds($seconds)->format('Y-m-d H:i:s.uP');
    }

    public function dbPastTimestamp(int $seconds, ?Connection $connection = null): string
    {
        $conn = $connection ?? $this->connection();
        if ($conn->getDriverName() === 'pgsql' && ! Carbon::hasTestNow()) {
            $row = $conn->selectOne('SELECT (clock_timestamp() - make_interval(secs => ?))::text AS ts', [$seconds]);
            if ($row instanceof stdClass && is_string($row->ts ?? null)) {
                return $row->ts;
            }
        }

        return Carbon::now('UTC')->subSeconds($seconds)->format('Y-m-d H:i:s.uP');
    }

    public function isFuture(?string $timestamp, ?Connection $connection = null): bool
    {
        if ($timestamp === null || trim($timestamp) === '') {
            return false;
        }

        $conn = $connection ?? $this->connection();
        if ($conn->getDriverName() === 'pgsql' && ! Carbon::hasTestNow()) {
            $row = $conn->selectOne(
                'SELECT CASE WHEN CAST(? AS timestamptz) > clock_timestamp() THEN 1 ELSE 0 END AS is_future',
                [$timestamp],
            );

            return $row instanceof stdClass && is_numeric($row->is_future ?? null) && (int) $row->is_future === 1;
        }

        return CarbonImmutable::parse($timestamp, 'UTC')->greaterThan(CarbonImmutable::instance(Carbon::now('UTC')));
    }
}
