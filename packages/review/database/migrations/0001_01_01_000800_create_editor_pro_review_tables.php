<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\EditorPro\Review\Schema\ReviewSchema;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    private const array ENTITIES = ['document_revision_pins', 'document_comment_anchors'];

    public function up(): void
    {
        $schema = Container::getInstance()->make(ReviewSchema::class);
        foreach (self::ENTITIES as $entity) {
            $schema->create($entity);
        }
    }

    public function down(): void
    {
        $schema = Container::getInstance()->make(ReviewSchema::class);
        foreach (array_reverse(self::ENTITIES) as $entity) {
            $schema->drop($entity);
        }
    }
};
