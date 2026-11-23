<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\EditorPro\Review\Schema\ReviewSchema;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        Container::getInstance()->make(ReviewSchema::class)->create('document_suggestions');
    }

    public function down(): void
    {
        Container::getInstance()->make(ReviewSchema::class)->drop('document_suggestions');
    }
};
