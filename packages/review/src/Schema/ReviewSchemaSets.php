<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Schema;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaScope;

/**
 * Declares the versioned database schema sets owned by `axiomasoft/vaulter-editor-pro-review`.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ReviewSchemaSets implements SchemaSetProvider
{
    /** @return list<SchemaSet> */
    #[Override]
    public function sets(): array
    {
        return [
            new SchemaSet(
                package: 'axiomasoft/vaulter-editor-pro-review',
                name: 'review',
                scope: SchemaScope::Central,
                path: dirname(__DIR__, 2).'/database/migrations',
                version: 1,
                drivers: ['pgsql', 'sqlite', 'mysql', 'mariadb'],
            ),
        ];
    }
}
