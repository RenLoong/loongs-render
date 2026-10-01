<?php

declare(strict_types=1);

namespace Loongs\Render;

use Loongs\Render\Contract\FieldPolicy;
use Loongs\Render\Contract\PermissionResolver;
use Loongs\Render\Policy\AllowAll;

/**
 * @internal State of one render pass: permission + field policy + client trimming. Every node's
 * toArray() asks it whether it stays; it also records the codes / fields it was asked about
 * (PageRegistry::inspect()).
 */
final class RenderContext
{
    /** @var array<string, true> */
    public private(set) array $codes = [];
    /** @var array<string, true> "resource.field" */
    public private(set) array $fields = [];
    private ?string $resource = null;

    public function __construct(
        public readonly PageContext $page,
        public readonly PermissionResolver $permissions = new AllowAll(),
        public readonly ?FieldPolicy $fieldPolicy = null,
        public readonly ComponentCatalog $catalog = new ComponentCatalog(),
    ) {
    }

    public ClientInfo $client {
        get => $this->page->client;
    }

    public bool $mobile {
        get => $this->page->client->isMobile;
    }

    /** any-of: true when no codes are required or one of them is granted */
    public function allows(array $codes): bool
    {
        if ($codes === []) {
            return true;
        }
        $ok = false;
        foreach ($codes as $c) {
            $this->codes[$c] = true;
            $ok = $ok || $this->permissions->allows($c, $this->page);
        }

        return $ok;
    }

    /** @param list<Platform>|null $platforms */
    public function onPlatform(?array $platforms): bool
    {
        return $platforms === null || in_array($this->page->client->platform, $platforms, true);
    }

    public function supports(string $kind, string $component): bool
    {
        return $this->catalog->supports($this->page->client, $kind, $component);
    }

    public function access(?string $resource, string $field): FieldAccess
    {
        $resource ??= $this->resource;
        if ($resource === null) {
            return FieldAccess::Editable;
        }
        $this->fields["{$resource}.{$field}"] = true;

        return $this->fieldPolicy?->access($resource, $field, $this->page) ?? FieldAccess::Editable;
    }

    /** Run $fn with $resource as the default field resource (builders, embedded sections). */
    public function withResource(?string $resource, callable $fn): mixed
    {
        $prev = $this->resource;
        $this->resource = $resource ?? $prev;
        try {
            return $fn();
        } finally {
            $this->resource = $prev;
        }
    }
}
