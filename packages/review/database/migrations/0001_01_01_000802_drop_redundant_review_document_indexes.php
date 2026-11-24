<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    /** @var list<string> */
    private const array ENTITIES = [
        'document_revision_pins',
        'document_comment_anchors',
        'document_suggestions',
    ];

    public function up(): void
    {
        $helpers = Container::getInstance()->make(SchemaHelpers::class);
        $schema = $helpers->schemaConnection();

        foreach (self::ENTITIES as $entity) {
            if (! $schema->hasTable($helpers->table($entity))) {
                continue;
            }
            foreach (self::redundantIndexNames($helpers->table($entity)) as $index) {
                if ($schema->hasIndex($helpers->table($entity), $index)) {
                    $schema->table($helpers->table($entity), static function (Blueprint $blueprint) use ($index): void {
                        $blueprint->dropIndex($index);
                    });
                }
            }
        }
    }

    public function down(): void
    {
        $helpers = Container::getInstance()->make(SchemaHelpers::class);
        $schema = $helpers->schemaConnection();
        $mysql = $helpers->dbConnection()->getDriverName() === 'mysql';

        foreach (self::ENTITIES as $entity) {
            $table = $helpers->table($entity);
            if (! $schema->hasTable($table)) {
                continue;
            }
            $indexes = [$table.'_document_id_index'];
            if ($mysql) {
                $indexes[] = $table.'_document_id_fk_idx';
            }
            foreach ($indexes as $index) {
                if (! $schema->hasIndex($table, $index)) {
                    $schema->table($table, static function (Blueprint $blueprint) use ($index): void {
                        $blueprint->index('document_id', $index);
                    });
                }
            }
        }
    }

    /** @return list<string> */
    private static function redundantIndexNames(string $table): array
    {
        return [
            $table.'_document_id_index',
            $table.'_document_id_fk_idx',
        ];
    }
};
