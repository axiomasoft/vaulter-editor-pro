<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Enums;

use Packman\Attributes\Api;

/**
 * Supported `v_document_suggestions.kind` profiles across text (8.2) and structural (8.3) review.
 */
#[Api(since: '1.0')]
enum SuggestionKind: string
{
    case Insert = 'insert';
    case Delete = 'delete';
    case Replace = 'replace';
    case MarkChange = 'mark_change';
    case AttrChange = 'attr_change';
    case BlockSplit = 'block_split';
    case BlockJoin = 'block_join';
    case ListChange = 'list_change';
    case TableChange = 'table_change';

    public function isText(): bool
    {
        return match ($this) {
            self::Insert, self::Delete, self::Replace => true,
            default => false,
        };
    }

    public function isStructural(): bool
    {
        return ! $this->isText();
    }

    /** @return list<string> */
    public static function allValues(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /** @return list<string> */
    public static function textValues(): array
    {
        return [
            self::Insert->value,
            self::Delete->value,
            self::Replace->value,
        ];
    }

    /** @return list<string> */
    public static function structuralValues(): array
    {
        return [
            self::MarkChange->value,
            self::AttrChange->value,
            self::BlockSplit->value,
            self::BlockJoin->value,
            self::ListChange->value,
            self::TableChange->value,
        ];
    }
}
