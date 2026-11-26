<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\EditorPro\Exchange\Schema\ExchangeSchema;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        Container::getInstance()->make(ExchangeSchema::class)->upgradeMysqlJobKeys();
    }

    public function down(): void
    {
        // The original creation migration also uses these keys. Restoring wide identity columns can invalidate the unique index.
    }
};
