<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\EditorPro\Exchange\Schema\ExchangeSchema;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        Container::getInstance()->make(ExchangeSchema::class)->create('document_exchange_jobs');
    }

    public function down(): void
    {
        Container::getInstance()->make(ExchangeSchema::class)->drop('document_exchange_jobs');
    }
};
