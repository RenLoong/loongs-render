<?php

declare(strict_types=1);

namespace Loongs\Render\Http;

/** ETag / If-None-Match helpers for page descriptions (RENDER.md §4.5). */
final class Etag
{
    /** Strong ETag from a rendered description (its `version` hash). */
    public static function of(array $description): string
    {
        $v = is_string($description['version'] ?? null) && $description['version'] !== ''
            ? $description['version']
            : substr(hash('sha256', json_encode($description, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 12);

        return '"' . $v . '"';
    }

    /** True when the If-None-Match header matches $etag (→ 304). Handles lists, W/ and *. */
    public static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }
        if (trim($ifNoneMatch) === '*') {
            return true;
        }
        $want = self::opaque($etag);
        foreach (explode(',', $ifNoneMatch) as $tag) {
            if (self::opaque($tag) === $want) {
                return true;
            }
        }

        return false;
    }

    private static function opaque(string $tag): string
    {
        $tag = trim($tag);
        if (str_starts_with($tag, 'W/')) {
            $tag = substr($tag, 2);
        }

        return trim($tag, '"');
    }
}
