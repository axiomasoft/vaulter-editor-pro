<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Runtime;

use JsonException;
use Packman\Attributes\Internal;
use stdClass;

/**
 * Deterministic JSON canonicalizer (lexicographically sorted object keys) and SHA-256 fingerprint helpers.
 */
#[Internal]
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function sha256Hex(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function sha256Raw(mixed $value): string
    {
        return hash('sha256', self::encode($value), binary: true);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws JsonException
     */
    public static function decodeObject(string $json): ?array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return null;
        }

        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $vars = get_object_vars($value);
            ksort($vars, SORT_STRING);
            $out = [];
            foreach ($vars as $k => $v) {
                $out[(string) $k] = self::normalize($v);
            }

            return (object) $out;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = self::normalize($v);
        }

        return $out;
    }
}
