<?php

declare(strict_types=1);

namespace Loongs\Render\Policy;

use Loongs\Render\Contract\PermissionResolver;
use Loongs\Render\PageContext;

/** Fixed list of granted codes ("*" = all). Handy for tests and simple hosts. */
final class CodeList implements PermissionResolver
{
    /** @var array<string, true> */
    private array $codes;

    /** @param list<string> $codes */
    public function __construct(array $codes)
    {
        $this->codes = array_fill_keys($codes, true);
        ksort($this->codes);
    }

    public function allows(string $code, PageContext $ctx): bool
    {
        return isset($this->codes['*']) || isset($this->codes[$code]);
    }

    public function fingerprint(PageContext $ctx): string
    {
        return substr(hash('sha256', implode(',', array_keys($this->codes))), 0, 16);
    }
}
