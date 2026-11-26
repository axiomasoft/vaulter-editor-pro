<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        $helpers = Container::getInstance()->make(SchemaHelpers::class);
        $schema = $helpers->schemaConnection();
        $table = $helpers->table('document_exchange_jobs');
        if (! $schema->hasColumn($table, 'reserved_suggestions')) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->unsignedInteger('reserved_suggestions')->default(0));
        }
        if (! $schema->hasColumn($table, 'reservation_command_id')) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->uuid('reservation_command_id')->nullable());
        }
    }

    public function down(): void
    {
        $helpers = Container::getInstance()->make(SchemaHelpers::class);
        $schema = $helpers->schemaConnection();
        $table = $helpers->table('document_exchange_jobs');
        foreach (['reservation_command_id', 'reserved_suggestions'] as $column) {
            if ($schema->hasColumn($table, $column)) {
                $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
            }
        }
    }
};
