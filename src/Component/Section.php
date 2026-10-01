<?php

declare(strict_types=1);

namespace Loongs\Render\Component;

use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\RenderContext;

/** Form section: optional title + fields; removed when all its fields are removed. */
final class Section extends Node
{
    private bool $collapsible = false;
    private ?string $resource = null;

    /** @param list<Field> $fields */
    private function __construct(
        public private(set) ?string $title,
        public private(set) array $fields,
    ) {
        foreach ($fields as $f) {
            $f instanceof Field || throw new InvalidDefinition('section ' . ($title ?? '') . ': fields must be Field');
        }
    }

    /** @param list<Field> $fields */
    public static function make(?string $title, array $fields): self
    {
        return new self($title, array_values($fields));
    }

    public function collapsible(bool $collapsible = true): self { $this->collapsible = $collapsible; return $this; }
    public function resource(string $resource): self { $this->resource = $resource; return $this; }

    public function toArray(RenderContext $rc): ?array
    {
        if (!$this->visible($rc)) {
            return null;
        }
        $fields = $rc->withResource($this->resource, fn (): array => self::render($this->fields, $rc));

        return $fields === [] ? null : self::compact(['title' => $this->title, 'collapsible' => $this->collapsible ?: null, 'fields' => $fields]);
    }
}
