<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\ModuleSchema;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Schema\ValueObjects\KeyColumnExpectation;

/**
 * Schema builder for the review extension tables (`v_document_revision_pins` and
 * `v_document_comment_anchors`) matching `plan/spec/database/sql/editor-pro-review.sql`.
 */
#[Internal]
final class ReviewSchema implements ModuleSchema
{
    private const string MAX_JS_INT = '9007199254740991';

    public function __construct(
        private readonly SchemaHelpers $helpers,
        private readonly IdsOptions $idsOptions,
    ) {}

    /** @return list<string> */
    public function entities(): array
    {
        return [
            'document_revision_pins',
            'document_comment_anchors',
        ];
    }

    /** @return list<KeyColumnExpectation> */
    public function keyColumns(): array
    {
        return [
            new KeyColumnExpectation('document_revision_pins', 'revision_id', 'REF'),
            new KeyColumnExpectation('document_revision_pins', 'document_id', 'REF'),
            new KeyColumnExpectation('document_revision_pins', 'tenant_id', 'HK'),
            new KeyColumnExpectation('document_comment_anchors', 'tenant_id', 'HK'),
            new KeyColumnExpectation('document_comment_anchors', 'comment_id', 'REF'),
            new KeyColumnExpectation('document_comment_anchors', 'document_id', 'REF'),
        ];
    }

    public function create(string $entity): void
    {
        $ids = $this->helpers->ids();

        $this->helpers->schemaConnection()->create(
            $this->helpers->table($entity),
            function (Blueprint $table) use ($ids, $entity): void {
                match ($entity) {
                    'document_revision_pins' => $this->defineRevisionPins($table, $ids),
                    'document_comment_anchors' => $this->defineCommentAnchors($table, $ids),
                    default => throw new InvalidArgumentException(sprintf('Unknown review entity [%s].', $entity)),
                };
            },
        );

        $this->afterCreate($entity);
    }

    public function drop(string $entity): void
    {
        $this->helpers->schemaConnection()->dropIfExists($this->helpers->table($entity));
    }

    private function defineRevisionPins(Blueprint $table, IdStrategy $ids): void
    {
        $name = $table->getTable();
        $driver = $this->driver();

        $ids->reference(table: $table, column: 'revision_id');
        Arr::last($table->getColumns())?->index(false);

        $ids->reference(table: $table, column: 'document_id');
        Arr::last($table->getColumns())?->index(false);

        HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: false, index: false);

        if ($driver === 'sqlite') {
            $table->rawColumn(
                'pin_reason',
                "VARCHAR(32) CHECK(pin_reason IN ('manual_version', 'review_base', 'comment_history', 'exchange_source'))",
            );
            $table->rawColumn(
                'source_generation',
                'BIGINT CHECK(typeof(source_generation) = \'integer\' AND source_generation BETWEEN 0 AND '.self::MAX_JS_INT.')',
            );
            $table->rawColumn(
                'source_position',
                'BIGINT CHECK(typeof(source_position) = \'integer\' AND source_position BETWEEN 0 AND '.self::MAX_JS_INT.')',
            );
            $table->rawColumn(
                'pinned_by_subject',
                'VARCHAR(255) CHECK(length(CAST(pinned_by_subject AS BLOB)) BETWEEN 1 AND 255)',
            );
        } else {
            $table->string('pin_reason', 32);
            $table->bigInteger('source_generation');
            $table->bigInteger('source_position');
            $subjectColumn = $table->string('pinned_by_subject', 255);
            if ($driver === 'pgsql') {
                $subjectColumn->collation('C');
            }
        }

        $table->timestampTz('pinned_at')->useCurrent();
        $table->timestampTz('expires_at')->nullable();

        $table->primary('revision_id', $name.'_pkey');
        $this->helpers->foreignKeyIndex(table: $table, column: 'document_id');
        if ($driver !== 'pgsql') {
            $table->index(['document_id', 'pinned_at'], $name.'_doc');
        }

        $table->foreign('revision_id', $name.'_revision_fk')
            ->references('id')
            ->on($this->helpers->table('document_revisions'))
            ->cascadeOnDelete();

        $table->foreign('document_id', $name.'_document_fk')
            ->references('id')
            ->on($this->helpers->table('documents'))
            ->cascadeOnDelete();
    }

    private function defineCommentAnchors(Blueprint $table, IdStrategy $ids): void
    {
        $name = $table->getTable();
        $driver = $this->driver();

        $ids->key(table: $table);
        HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: false, index: false);

        $ids->reference(table: $table, column: 'comment_id');
        Arr::last($table->getColumns())?->index(false);

        $ids->reference(table: $table, column: 'document_id');
        Arr::last($table->getColumns())?->index(false);

        if ($driver === 'sqlite') {
            $table->rawColumn(
                'generation',
                'BIGINT CHECK(typeof(generation) = \'integer\' AND generation BETWEEN 0 AND '.self::MAX_JS_INT.')',
            );
            $table->rawColumn(
                'source_position',
                'BIGINT CHECK(typeof(source_position) = \'integer\' AND source_position BETWEEN 0 AND '.self::MAX_JS_INT.')',
            );
            $table->rawColumn(
                'schema_version',
                'INTEGER CHECK(typeof(schema_version) = \'integer\' AND schema_version > 0)',
            );
            $table->rawColumn(
                'block_id',
                'VARCHAR(255) CHECK(length(CAST(block_id AS BLOB)) BETWEEN 1 AND 255)',
            );
            $table->rawColumn(
                'selector_kind',
                "VARCHAR(24) CHECK(selector_kind IN ('snapshot_utf16', 'yjs_relative'))",
            );
            $table->jsonb('selector_payload');
            $table->text('quote_text');
            $table->rawColumn(
                'anchor_state',
                "VARCHAR(16) CHECK(anchor_state IN ('attached', 'detached', 'historical'))",
            )->default('attached');
            $table->rawColumn(
                'version',
                'INTEGER CHECK(typeof(version) = \'integer\' AND version > 0)',
            )->default(1);
        } else {
            $table->bigInteger('generation');
            $table->bigInteger('source_position');
            $table->integer('schema_version');
            $blockIdColumn = $table->string('block_id', 255);
            if ($driver === 'pgsql') {
                $blockIdColumn->collation('C');
            }
            $table->string('selector_kind', 24);
            $table->jsonb('selector_payload');
            $table->text('quote_text');
            $table->string('anchor_state', 16)->default('attached');
            $table->integer('version')->default(1);
        }

        $table->timestampTz('created_at')->useCurrent();
        $table->timestampTz('updated_at')->useCurrent();

        $table->unique('comment_id', $name.'_comment_uniq');
        $this->helpers->foreignKeyIndex(table: $table, column: 'document_id');
        $table->index(['document_id', 'generation', 'anchor_state', 'block_id'], $name.'_doc');

        $table->foreign('comment_id', $name.'_comment_fk')
            ->references('id')
            ->on($this->helpers->table('node_comments'))
            ->cascadeOnDelete();

        $table->foreign('document_id', $name.'_document_fk')
            ->references('id')
            ->on($this->helpers->table('documents'))
            ->cascadeOnDelete();
    }

    private function afterCreate(string $entity): void
    {
        if ($this->driver() !== 'pgsql') {
            return;
        }

        $conn = $this->helpers->dbConnection();
        $grammar = $conn->getQueryGrammar();
        $name = $this->helpers->table($entity);
        $wrappedTable = $grammar->wrapTable($name);

        if ($conn->transactionLevel() > 0) {
            $conn->statement("SET LOCAL lock_timeout = '2s'");
            $conn->statement("SET LOCAL statement_timeout = '15s'");
        }

        if ($entity === 'document_revision_pins') {
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (pin_reason IN ('manual_version', 'review_base', 'comment_history', 'exchange_source'))");
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (source_generation BETWEEN 0 AND ".self::MAX_JS_INT.')');
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (source_position BETWEEN 0 AND ".self::MAX_JS_INT.')');
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (octet_length(pinned_by_subject) BETWEEN 1 AND 255)");
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (expires_at IS NULL OR expires_at > pinned_at)");

            $index = $grammar->wrap($name.'_doc');
            $conn->statement("CREATE INDEX {$index} ON {$wrappedTable} (document_id, pinned_at DESC)");

            return;
        }

        if ($entity === 'document_comment_anchors') {
            if ($this->idsOptions->strategy === 'uuid7') {
                $conn->statement("ALTER TABLE {$wrappedTable} ALTER COLUMN id SET DEFAULT uuidv7()");
            }
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (generation BETWEEN 0 AND ".self::MAX_JS_INT.')');
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (source_position BETWEEN 0 AND ".self::MAX_JS_INT.')');
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (schema_version > 0)");
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (octet_length(block_id) BETWEEN 1 AND 255)");
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (selector_kind IN ('snapshot_utf16', 'yjs_relative'))");
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (jsonb_typeof(selector_payload) = 'object')");
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (anchor_state IN ('attached', 'detached', 'historical'))");
            $conn->statement("ALTER TABLE {$wrappedTable} ADD CHECK (version > 0)");
        }
    }

    private function driver(): string
    {
        $driver = $this->helpers->dbConnection()->getDriverName();

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }
}
