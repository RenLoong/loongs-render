<?php

declare(strict_types=1);

namespace Loongs\Render;

use Loongs\Render\Exception\InvalidPageCode;

/**
 * Page codes: [a-z0-9._-], starting with a letter or digit, ≤ 128 characters, e.g. uuims.system.users.
 * Safe in URL paths, uni-app page parameters and mini program paths without escaping.
 */
final class PageCode
{
    public const PATTERN = '/^[a-z0-9][a-z0-9._-]{0,127}$/';
    /** same rule for JSON Schema / JavaScript */
    public const JSON_PATTERN = '^[a-z0-9][a-z0-9._-]{0,127}$';

    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }

    public static function assert(string $code): string
    {
        if (!self::isValid($code)) {
            throw new InvalidPageCode(sprintf('Invalid page code "%s": use [a-z0-9._-] only, start with a letter or digit, max 128 characters.', $code));
        }

        return $code;
    }
}
