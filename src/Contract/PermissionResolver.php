<?php

declare(strict_types=1);

namespace Loongs\Render\Contract;

use Loongs\Render\PageContext;

/**
 * Host-application permission check (admin: P1 permission codes). Used for the page permission of
 * #[AsPage] and every ->can() on actions, fields, columns, blocks, tabs and sections.
 */
interface PermissionResolver
{
    public function allows(string $code, PageContext $ctx): bool;

    /**
     * Stable fingerprint of the current user's permissions (part of the description cache key,
     * RENDER.md §4.5). Return '' when caching per user is not wanted.
     */
    public function fingerprint(PageContext $ctx): string;
}
