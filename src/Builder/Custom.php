<?php

declare(strict_types=1);

namespace Loongs\Render\Builder;

use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\PageType;
use Loongs\Render\RenderContext;

/**
 * Custom page (RENDER.md §9 escape hatch): a client-side named page component plus JSON config, e.g.
 * Custom::make('角色权限')->component('role-permission-editor')->config(['api' => '/uuims/api/roles']).
 */
class Custom extends Builder
{
    private ?string $component = null;
    private array $config = [];

    public function type(): PageType
    {
        return PageType::Custom;
    }

    public function component(string $name): static
    {
        $this->component = preg_match('/^[a-z][a-z0-9-]{0,63}$/', $name) === 1 ? $name : throw new InvalidDefinition("custom component must be [a-z][a-z0-9-], got \"{$name}\"");

        return $this;
    }

    public function config(array $config): static
    {
        array_walk_recursive($config, static function ($v, $k): void {
            (is_scalar($v) || $v === null) || throw new InvalidDefinition("custom config.{$k} must be plain JSON data");
        });
        $this->config = $config;

        return $this;
    }

    public function validate(): void
    {
        $this->component !== null || throw new InvalidDefinition("custom {$this->title}: component() is required");
    }

    protected function body(RenderContext $rc): array
    {
        return ['component' => $this->component, 'config' => (object) $this->config];
    }
}
