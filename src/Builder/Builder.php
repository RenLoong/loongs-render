<?php

declare(strict_types=1);

namespace Loongs\Render\Builder;

use Loongs\Render\Component\Node;
use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\PageType;
use Loongs\Render\Platform;
use Loongs\Render\RenderContext;
use Loongs\Render\Support\Placeholder;

/**
 * Base of the page builders (RENDER.md §4.3, §6). A builder produces the `body` of the description; the
 * shell (schema, page, type, title, version, breadcrumb, meta) is added by PageRegistry.
 */
abstract class Builder extends Node
{
    /** @var list<string> */
    public private(set) array $breadcrumb = [];
    public private(set) ?string $resource = null;

    final protected function __construct(public private(set) string $title)
    {
        Placeholder::assert($title, 'page title');
    }

    public static function make(string $title): static
    {
        return new static($title);
    }

    abstract public function type(): PageType;

    /** @return array<string, mixed> */
    abstract protected function body(RenderContext $rc): array;

    /** Structural checks before rendering (required parts present…). */
    public function validate(): void
    {
    }

    /** @param list<string> $items */
    public function breadcrumb(array $items): static
    {
        foreach ($items as $i) {
            is_string($i) || throw new InvalidDefinition('breadcrumb items must be strings');
            Placeholder::assert($i, 'breadcrumb');
        }
        $this->breadcrumb = array_values($items);

        return $this;
    }

    /** Default field-permission resource for every field / column of this page (FieldPolicy). */
    public function resource(string $resource): static
    {
        $this->resource = preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $resource) === 1 ? $resource : throw new InvalidDefinition("invalid resource \"{$resource}\"");

        return $this;
    }

    /** @return list<Platform> meta.platforms */
    public function supportedPlatforms(): array
    {
        return $this->platforms ?? Platform::cases();
    }

    /** @return array<string, mixed> */
    final public function toArray(RenderContext $rc): array
    {
        $this->validate();

        return $rc->withResource($this->resource, fn (): array => $this->body($rc));
    }
}
