<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    private const array CURSOR_COLUMNS = [
        'document_revision_pins' => 'revision_id',
        'document_suggestions' => 'id',
    ];

    public function up(): void
    {
        $helpers = Container::getInstance()->make(SchemaHelpers::class);
        $schema = $helpers->schemaConnection();
        foreach (self::CURSOR_COLUMNS as $entity => $key) {
            $table = $helpers->table($entity);
            $index = $table.'_page';
            if (! $schema->hasIndex($table, $index)) {
                $schema->table($table, static function (Blueprint $blueprint) use ($key, $index): void {
                    $blueprint->index(['document_id', $key], $index);
                });
            }
        }
    }

    public function down(): void
    {
        $helpers = Container::getInstance()->make(SchemaHelpers::class);
        $schema = $helpers->schemaConnection();
        foreach (array_keys(self::CURSOR_COLUMNS) as $entity) {
            $table = $helpers->table($entity);
            $index = $table.'_page';
            if ($schema->hasIndex($table, $index)) {
                $schema->table($table, static function (Blueprint $blueprint) use ($index): void {
                    $blueprint->dropIndex($index);
                });
            }
        }
    }
};
