<?php

declare(strict_types=1);

namespace Loongs\Render;

use Attribute;

/**
 * Declares a page class (RENDER.md §4.2):
 *   #[AsPage('uuims.system.users', permission: 'system:uuims')]
 *
 * permission: one code or a list (any-of); null = only login required (decided by the host).
 * version:    bump to invalidate caches when the page changes in a way the class hash doesn't show;
 *             default = hash of the class file.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsPage
{
    /** @var list<string> */
    public array $permissions;

    /** @param string|list<string>|null $permission */
    public function __construct(
        public string $code,
        string|array|null $permission = null,
        public ?string $version = null,
    ) {
        $this->permissions = $permission === null ? [] : array_values((array) $permission);
    }
}
