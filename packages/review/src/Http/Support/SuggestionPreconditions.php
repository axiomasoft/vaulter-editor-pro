<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Support;

use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Exceptions\Http\PreconditionRequiredException;

#[Internal]
final class SuggestionPreconditions
{
    /** @return list<string>|null */
    public static function tags(Request $request): ?array
    {
        $header = $request->headers->get('If-Match') ?? $request->headers->get('X-Vaulter-If-Match');
        if ($header === null) {
            return null;
        }
        if (preg_match('/\A\s*(?:\*|(?:W\/)?"[^"]*")(?:\s*,\s*(?:\*|(?:W\/)?"[^"]*"))*\s*\z/', $header) !== 1) {
            throw PreconditionRequiredException::missing('If-Match');
        }
        preg_match_all('/\*|(?:W\/)?"[^"]*"/', $header, $matches);
        foreach ($matches[0] as $tag) {
            if ($tag === '*') {
                throw PreconditionRequiredException::wildcard();
            }
            if (str_starts_with($tag, 'W/')) {
                throw StaleWriteException::of('review.suggestion', 'weak_etag');
            }
        }

        return $matches[0];
    }
}
