<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\ModuleSchema;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Schema\ValueObjects\KeyColumnExpectation;

/**
 * Schema builder for `v_document_exchange_jobs` matching `plan/spec/database/sql/editor-pro-exchange.sql`.
 */
#[Internal]
final class ExchangeSchema implements ModuleSchema
{
    private const string MAX_JS_INT = '9007199254740991';

    public function __construct(
        private readonly SchemaHelpers $helpers,
    ) {}

    /** @return list<string> */
    public function entities(): array
    {
        return ['document_exchange_jobs', 'document_sections'];
    }

    /** @return list<KeyColumnExpectation> */
    public function keyColumns(): array
    {
        return [
            new KeyColumnExpectation('document_exchange_jobs', 'tenant_id', 'HK'),
            new KeyColumnExpectation('document_exchange_jobs', 'document_id', 'REF'),
            new KeyColumnExpectation('document_exchange_jobs', 'source_blob_id', 'REF'),
            new KeyColumnExpectation('document_exchange_jobs', 'source_revision_id', 'REF'),
            new KeyColumnExpectation('document_exchange_jobs', 'output_blob_id', 'REF'),
            new KeyColumnExpectation('document_sections', 'tenant_id', 'HK'),
            new KeyColumnExpectation('document_sections', 'document_id', 'REF'),
        ];
    }

    public function create(string $entity): void
    {
        if (! in_array($entity, $this->entities(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown exchange entity [%s].', $entity));
        }

        $ids = $this->helpers->ids();

        $this->helpers->schemaConnection()->create(
            $this->helpers->table($entity),
            function (Blueprint $table) use ($ids, $entity): void {
                match ($entity) {
                    'document_exchange_jobs' => $this->defineExchangeJobs($table, $ids),
                    'document_sections' => $this->defineDocumentSections($table, $ids),
                    default => throw new InvalidArgumentException(sprintf('Unknown exchange entity [%s].', $entity)),
                };
            },
        );

        $this->afterCreate($entity);
    }

    public function drop(string $entity): void
    {
        $this->helpers->schemaConnection()->dropIfExists($this->helpers->table($entity));
    }

    private function defineExchangeJobs(Blueprint $table, IdStrategy $ids): void
    {
        $name = $table->getTable();
        $driver = $this->driver();

        $table->uuid('id')->primary();
        HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: false, index: false);

        if ($driver === 'sqlite') {
            $table->rawColumn(
                'project_scope',
                'VARCHAR(255) NOT NULL CHECK(length(CAST(project_scope AS BLOB)) BETWEEN 1 AND 255)',
            );
            $table->rawColumn(
                'effective_subject',
                'VARCHAR(255) NOT NULL CHECK(length(CAST(effective_subject AS BLOB)) BETWEEN 1 AND 255)',
            );
        } else {
            $scopeCol = $table->string('project_scope', 255);
            $subjectCol = $table->string('effective_subject', 255);
            if ($driver === 'pgsql') {
                $scopeCol->collation('C');
                $subjectCol->collation('C');
            }
        }

        $ids->reference(table: $table, column: 'document_id');
        Arr::last($table->getColumns())?->index(false);

        if ($driver === 'sqlite') {
            $table->rawColumn(
                'request_key',
                'VARCHAR(255) NOT NULL CHECK(length(CAST(request_key AS BLOB)) BETWEEN 1 AND 255)',
            );
            $table->rawColumn(
                'request_fingerprint',
                'BLOB NOT NULL CHECK(length(CAST(request_fingerprint AS BLOB)) = 32)',
            );
            $table->rawColumn(
                'kind',
                "VARCHAR(16) NOT NULL CHECK(kind IN ('import', 'export'))",
            );
            $table->rawColumn(
                'format',
                "VARCHAR(16) NOT NULL CHECK(format IN ('docx'))",
            );
            $table->rawColumn(
                'profile_id',
                "VARCHAR(64) NOT NULL CHECK(profile_id <> '')",
            );
        } else {
            $reqKeyCol = $table->string('request_key', 255);
            if ($driver === 'pgsql') {
                $reqKeyCol->collation('C');
            }
            $table->binary('request_fingerprint');
            $table->string('kind', 16);
            $table->string('format', 16);
            $table->string('profile_id', 64);
        }

        $ids->reference(table: $table, column: 'source_blob_id', nullable: true);
        Arr::last($table->getColumns())?->index(false);

        $ids->reference(table: $table, column: 'source_revision_id', nullable: true);
        Arr::last($table->getColumns())?->index(false);

        if ($driver === 'sqlite') {
            $table->rawColumn(
                'source_generation',
                'BIGINT CHECK(source_generation IS NULL OR (typeof(source_generation) = \'integer\' AND source_generation BETWEEN 0 AND '.self::MAX_JS_INT.'))',
            )->nullable();
            $table->rawColumn(
                'source_position',
                'BIGINT CHECK(source_position IS NULL OR (typeof(source_position) = \'integer\' AND source_position BETWEEN 0 AND '.self::MAX_JS_INT.'))',
            )->nullable();
        } else {
            $table->bigInteger('source_generation')->nullable();
            $table->bigInteger('source_position')->nullable();
        }

        $ids->reference(table: $table, column: 'output_blob_id', nullable: true);
        Arr::last($table->getColumns())?->index(false);

        if ($driver === 'sqlite') {
            $table->rawColumn(
                'apply_command_id',
                'VARCHAR(255) CHECK(apply_command_id IS NULL OR length(CAST(apply_command_id AS BLOB)) BETWEEN 1 AND 255)',
            )->nullable();
        } else {
            $applyCmdCol = $table->string('apply_command_id', 255)->nullable();
            if ($driver === 'pgsql') {
                $applyCmdCol->collation('C');
            }
        }

        $table->uuid('apply_receipt_id')->nullable();

        if ($driver === 'sqlite') {
            $table->rawColumn(
                'state',
                "VARCHAR(20) NOT NULL DEFAULT 'queued' CHECK(state IN ('queued', 'running', 'preview_ready', 'completed', 'applied', 'failed'))",
            );
        } else {
            $table->string('state', 20)->default('queued');
        }

        $table->jsonb('diagnostics_report')->nullable();
        $table->string('error_code', 64)->nullable();
        $table->uuid('lease_token')->nullable();
        $table->timestampTz('lease_until')->nullable();
        $table->timestampTz('created_at')->useCurrent();
        $table->timestampTz('updated_at')->useCurrent();
        $table->timestampTz('finished_at')->nullable();

        $table->unique(
            ['tenant_id', 'project_scope', 'effective_subject', 'document_id', 'request_key'],
            $name.'_uniq',
        );

        $table->index('source_blob_id', $name.'_source_blob_fk_idx');
        $table->index('source_revision_id', $name.'_source_rev_fk_idx');
        $table->index('output_blob_id', $name.'_output_blob_fk_idx');

        if ($driver !== 'pgsql') {
            $table->index(['document_id', 'created_at'], $name.'_doc');
            $table->index(['state', 'lease_until', 'created_at'], $name.'_queue');
        }

        $table->foreign('document_id', $name.'_document_fk')
            ->references('id')
            ->on($this->helpers->table('documents'))
            ->cascadeOnDelete();

        $table->foreign('source_blob_id', $name.'_source_blob_fk')
            ->references('id')
            ->on($this->helpers->table('blobs'))
            ->restrictOnDelete();

        $table->foreign('source_revision_id', $name.'_source_rev_fk')
            ->references('id')
            ->on($this->helpers->table('document_revisions'))
            ->restrictOnDelete();

        $table->foreign('output_blob_id', $name.'_output_blob_fk')
            ->references('id')
            ->on($this->helpers->table('blobs'))
            ->restrictOnDelete();
    }

    private function defineDocumentSections(Blueprint $table, IdStrategy $ids): void
    {
        $name = $table->getTable();
        $driver = $this->driver();

        $table->uuid('id')->primary();
        HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: false, index: false);
        $ids->reference(table: $table, column: 'document_id');
        $this->withoutLeadingDocumentIndex($table);

        if ($driver === 'sqlite') {
            $table->rawColumn(
                'generation',
                'BIGINT NOT NULL CHECK(typeof(generation) = \'integer\' AND generation BETWEEN 0 AND '.self::MAX_JS_INT.')',
            );
            $table->rawColumn(
                'source_position',
                'BIGINT NOT NULL CHECK(typeof(source_position) = \'integer\' AND source_position BETWEEN 0 AND '.self::MAX_JS_INT.')',
            );
            $table->rawColumn(
                'schema_version',
                'INTEGER NOT NULL CHECK(typeof(schema_version) = \'integer\' AND schema_version > 0)',
            );
        } else {
            $table->bigInteger('generation');
            $table->bigInteger('source_position');
            $table->integer('schema_version');
        }

        $table->jsonb('sections_json');
        $table->timestampTz('updated_at')->useCurrent();

        $table->unique(['document_id', 'generation'], $name.'_doc_gen_uniq');

        $table->foreign('document_id', $name.'_document_fk')
            ->references('id')
            ->on($this->helpers->table('documents'))
            ->cascadeOnDelete();
    }

    private function withoutLeadingDocumentIndex(Blueprint $table): void
    {
        $column = Arr::last($table->getColumns());
        if ($column instanceof ColumnDefinition) {
            $column->offsetSet('index', null);
        }
    }

    private function afterCreate(string $entity): void
    {
        $conn = $this->helpers->dbConnection();
        $grammar = $conn->getQueryGrammar();
        $name = $this->helpers->table($entity);
        $wrappedTable = $grammar->wrapTable($name);
        $driver = $this->driver();

        if ($driver === 'sqlite') {
            // Enforce table-level cross-column invariants on SQLite via a trigger-free check table alter isn't supported;
            // SQLite table CHECKs already validated single columns; enforce remaining pairs in application repository & SQLite connection pragmas.
            return;
        }

        if ($driver !== 'pgsql') {
            return;
        }

        if ($conn->transactionLevel() > 0) {
            $conn->statement("SET LOCAL lock_timeout = '2s'");
            $conn->statement("SET LOCAL statement_timeout = '15s'");
        }

        $conn->statement("ALTER TABLE {$wrappedTable} ALTER COLUMN id SET DEFAULT uuidv7()");

        if ($entity === 'document_sections') {
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (generation BETWEEN 0 AND ".self::MAX_JS_INT.')');
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (source_position BETWEEN 0 AND ".self::MAX_JS_INT.')');
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (schema_version > 0)");
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (jsonb_typeof(sections_json) = 'array')");

            return;
        }

        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (octet_length(project_scope) BETWEEN 1 AND 255)");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (octet_length(effective_subject) BETWEEN 1 AND 255)");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (octet_length(request_key) BETWEEN 1 AND 255)");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (octet_length(request_fingerprint) = 32)");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (kind IN ('import', 'export'))");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (format IN ('docx'))");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (profile_id <> '')");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (source_generation IS NULL OR source_generation BETWEEN 0 AND ".self::MAX_JS_INT.')');
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (source_position IS NULL OR source_position BETWEEN 0 AND ".self::MAX_JS_INT.')');
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (apply_command_id IS NULL OR octet_length(apply_command_id) BETWEEN 1 AND 255)");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (state IN ('queued', 'running', 'preview_ready', 'completed', 'applied', 'failed'))");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (diagnostics_report IS NULL OR jsonb_typeof(diagnostics_report) = 'object')");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK ((lease_token IS NULL) = (lease_until IS NULL))");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK ((kind = 'import' AND source_blob_id IS NOT NULL) OR (kind = 'export' AND source_generation IS NOT NULL AND source_position IS NOT NULL))");
        $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK ((state <> 'completed' OR (kind = 'export' AND output_blob_id IS NOT NULL)) AND (state <> 'applied' OR (kind = 'import' AND apply_command_id IS NOT NULL AND apply_receipt_id IS NOT NULL)))");

        $docIndex = $grammar->wrap($name.'_doc');
        $queueIndex = $grammar->wrap($name.'_queue');
        $conn->statement("CREATE INDEX {$docIndex} ON {$wrappedTable} (document_id, created_at DESC)");
        $conn->statement("CREATE INDEX {$queueIndex} ON {$wrappedTable} (state, lease_until, created_at) WHERE state IN ('queued', 'running')");
    }

    private function driver(): string
    {
        $driver = $this->helpers->dbConnection()->getDriverName();

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }
}
