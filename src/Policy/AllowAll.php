<?php

declare(strict_types=1);

namespace Loongs\Render\Policy;

use Loongs\Render\Contract\PermissionResolver;
use Loongs\Render\PageContext;

/** Every code allowed (development / tests / apps without permissions). */
final class AllowAll implements PermissionResolver
{
    public function allows(string $code, PageContext $ctx): bool
    {
        return true;
    }

    public function fingerprint(PageContext $ctx): string
    {
        return 'all';
    }
}
