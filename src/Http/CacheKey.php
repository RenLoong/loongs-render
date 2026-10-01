<?php

declare(strict_types=1);

namespace Loongs\Render\Http;

use Loongs\Render\ClientInfo;
use Loongs\Render\Contract\PermissionResolver;
use Loongs\Render\PageContext;
use Loongs\Render\PageDefinition;
use Loongs\Render\Schema;

/**
 * Cache key of a rendered description (RENDER.md §4.5): page code + platform (+env) + permission
 * fingerprint + page version (+ schema). Store with loongs/cache or anything else.
 */
final class CacheKey
{
    public const PREFIX = 'render';

    public static function of(PageDefinition $page, ClientInfo $client, string $permissionFingerprint): string
    {
        $fp = preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $permissionFingerprint) === 1 ? $permissionFingerprint : substr(hash('sha256', $permissionFingerprint), 0, 16);

        return implode(':', [self::PREFIX, 's' . Schema::VERSION, $page->code, $client->cacheKey(), $fp, $page->version]);
    }

    public static function for(PageDefinition $page, PageContext $ctx, PermissionResolver $permissions): string
    {
        return self::of($page, $ctx->client, $permissions->fingerprint($ctx->withPage($page->code)));
    }

    /** Prefix to drop every cached description of one page (or all with null). */
    public static function prefix(?string $code = null): string
    {
        return self::PREFIX . ':s' . Schema::VERSION . ':' . ($code !== null ? $code . ':' : '');
    }
}
