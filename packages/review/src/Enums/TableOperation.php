<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Enums;

use Packman\Attributes\Internal;

/** The `table_op` of a `table_change` structural suggestion. */
#[Internal]
enum TableOperation: string
{
    case InsertTable = 'insert_table';
    case InsertRow = 'insert_row';
    case DeleteRow = 'delete_row';
    case InsertColumn = 'insert_column';
    case DeleteColumn = 'delete_column';
    case ToggleHeaderRow = 'toggle_header_row';
    case ToggleHeaderCell = 'toggle_header_cell';
    case SetCellAttr = 'set_cell_attr';

    /** Whether the operation shifts the row or column indexes other selectors into the table use. */
    public function shiftsIndexes(): bool
    {
        return match ($this) {
            self::InsertRow, self::DeleteRow, self::InsertColumn, self::DeleteColumn => true,
            self::InsertTable, self::ToggleHeaderRow, self::ToggleHeaderCell, self::SetCellAttr => false,
        };
    }
}
