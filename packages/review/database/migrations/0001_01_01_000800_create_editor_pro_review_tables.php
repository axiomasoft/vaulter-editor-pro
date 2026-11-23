<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\EditorPro\Review\Schema\ReviewSchema;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        $schema = Container::getInstance()->make(ReviewSchema::class);
        foreach ($schema->entities() as $entity) {
            $schema->create($entity);
        }
    }

    public function down(): void
    {
        $schema = Container::getInstance()->make(ReviewSchema::class);
        foreach (array_reverse($schema->entities()) as $entity) {
            $schema->drop($entity);
        }
    }
};
