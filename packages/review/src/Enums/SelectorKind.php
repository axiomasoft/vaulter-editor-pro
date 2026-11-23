<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Enums;

use Packman\Attributes\Api;

/** Encoding kind of a generation-scoped comment range selector. */
#[Api(since: '1.0')]
enum SelectorKind: string
{
    case SnapshotUtf16 = 'snapshot_utf16';
    case YjsRelative = 'yjs_relative';
}
