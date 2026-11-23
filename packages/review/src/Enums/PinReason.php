<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Enums;

use Packman\Attributes\Api;

/** Reasons a document revision can be pinned against compaction or pruning. */
#[Api(since: '1.0')]
enum PinReason: string
{
    case ManualVersion = 'manual_version';
    case ReviewBase = 'review_base';
    case CommentHistory = 'comment_history';
    case ExchangeSource = 'exchange_source';
}
