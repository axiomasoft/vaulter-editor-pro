<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Support;

use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;

/** Formatting utilities for strong entity tags and ISO-8601 UTC wire timestamps. */
#[Internal]
final class ReviewTags
{
    public static function versioned(string $kind, string $id, int $version): string
    {
        return '"'.$kind.'.'.$id.'.'.$version.'"';
    }

    public static function comment(string $id, int $version): string
    {
        return self::versioned('comment', $id, $version);
    }

    public static function anchor(string $id, int $version): string
    {
        return self::versioned('comment_anchor', $id, $version);
    }

    public static function suggestion(string $id, int $version): string
    {
        return self::versioned('suggestion', $id, $version);
    }

    public static function iso(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
