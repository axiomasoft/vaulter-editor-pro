<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Repositories;

use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use stdClass;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\EditorPro\Review\Exceptions\InvalidCommentStateException;
use Vaulter\Support\Identity;

/**
 * Parameterized lookup and CAS helper for core documents and node comments
 * using the shared `VaulterDatabase` connection and scoped table names.
 */
#[Internal]
final readonly class ReviewLookup
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
     * Resolve the owning document row (`id`, `tenant_id`, `collab_epoch`, `state_version`, `schema_version`)
     * for a node key.
     *
     * @return array{id: int|string, tenant_id: int|string, collab_epoch: int, state_version: int, schema_version: int}
     */
    public function documentForNode(int|string $nodeId): array
    {
        if (! $this->fitsKey($nodeId)) {
            throw InvalidCommentStateException::targetIsNotDocument();
        }

        $row = $this->connection()
            ->table($this->table('documents'))
            ->where('node_id', $nodeId)
            ->first(['id', 'tenant_id', 'collab_epoch', 'state_version', 'schema_version']);

        if (! $row instanceof stdClass) {
            throw InvalidCommentStateException::targetIsNotDocument();
        }

        /** @var int|string|null $rawDocId */
        $rawDocId = $row->id ?? null;
        if (! is_int($rawDocId) && ! is_string($rawDocId)) {
            throw InvalidCommentStateException::targetIsNotDocument();
        }

        /** @var int|string|null $rawTenantId */
        $rawTenantId = $row->tenant_id ?? null;

        return [
            'id' => $rawDocId,
            'tenant_id' => $this->normalizeTenantId($rawTenantId),
            'collab_epoch' => is_numeric($row->collab_epoch ?? null) ? (int) $row->collab_epoch : 0,
            'state_version' => is_numeric($row->state_version ?? null) ? (int) $row->state_version : 0,
            'schema_version' => is_numeric($row->schema_version ?? null) ? (int) $row->schema_version : 1,
        ];
    }

    /**
     * Resolve `tenant_id` directly for a document row, falling back cleanly when tenancy is disabled.
     */
    public function tenantForDocument(int|string $documentId): int|string
    {
        if (! $this->fitsKey($documentId)) {
            return $this->defaultTenantSentinel();
        }

        $row = $this->connection()
            ->table($this->table('documents'))
            ->where('id', $documentId)
            ->first(['tenant_id']);

        /** @var int|string|null $rawTenantId */
        $rawTenantId = $row instanceof stdClass ? ($row->tenant_id ?? null) : null;

        return $this->normalizeTenantId($rawTenantId);
    }

    /**
     * Lock a `v_node_comments` row inside an active transaction, enforcing node scope.
     */
    public function lockCommentRow(int|string $nodeId, int|string $commentId): stdClass
    {
        if (! $this->fitsKey($nodeId) || ! $this->fitsKey($commentId)) {
            throw CommentNotFoundException::forNode($nodeId, $commentId);
        }

        $row = $this->connection()
            ->table($this->table('node_comments'))
            ->where('id', $commentId)
            ->where('node_id', $nodeId)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof stdClass) {
            throw CommentNotFoundException::forNode($nodeId, $commentId);
        }

        return $row;
    }

    public function fitsKey(int|string $value): bool
    {
        return Identity::fitsKey(new DocumentRevision, $value);
    }

    public function normalizeTenantId(int|string|null $tenantId): int|string
    {
        $key = Identity::key($tenantId);
        if ($key !== null && $key !== '') {
            return $key;
        }

        return $this->defaultTenantSentinel();
    }

    private function defaultTenantSentinel(): int|string
    {
        return match ($this->idsOptions->hostKeys) {
            'bigint' => 0,
            'ulid' => '00000000000000000000000000',
            'uuid' => '00000000-0000-0000-0000-000000000000',
            default => '0',
        };
    }
}
