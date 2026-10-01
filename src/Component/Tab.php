<?php

declare(strict_types=1);

namespace Loongs\Render\Component;

use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\RenderContext;
use Loongs\Render\Support\Placeholder;

/** Detail tab / collapse panel: title + blocks. Removed when every block is removed. */
final class Tab extends Node
{
    /** @param list<Block> $blocks */
    private function __construct(
        public private(set) string $title,
        public private(set) array $blocks,
    ) {
        Placeholder::assert($title, "tab {$title}");
        foreach ($blocks as $b) {
            $b instanceof Block || throw new InvalidDefinition("tab {$title}: blocks must be Block");
        }
    }

    /** @param list<Block> $blocks */
    public static function make(string $title, array $blocks): self
    {
        return new self($title, array_values($blocks));
    }

    public function toArray(RenderContext $rc): ?array
    {
        if (!$this->visible($rc)) {
            return null;
        }
        $blocks = self::render($this->blocks, $rc);

        return $blocks === [] ? null : ['title' => $this->title, 'blocks' => $blocks];
    }
}
