<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Enums;

use Packman\Attributes\Internal;

/** The `list_op` of a `list_change` structural suggestion. */
#[Internal]
enum ListOperation: string
{
    case WrapBullet = 'wrap_bullet';
    case WrapOrdered = 'wrap_ordered';
    case Lift = 'lift';
    case ToggleType = 'toggle_type';
    case SinkItem = 'sink_item';
    case SplitItem = 'split_item';
}
