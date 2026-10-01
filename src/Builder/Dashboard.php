<?php

declare(strict_types=1);

namespace Loongs\Render\Builder;

use Loongs\Render\Component\Block;
use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\PageType;
use Loongs\Render\RenderContext;

/** Dashboard page (RENDER.md §6.1): 24-column grid of independently loaded blocks; mobile stacks them. */
class Dashboard extends Builder
{
    /** @var list<Block> */
    private array $blocks = [];

    public function type(): PageType
    {
        return PageType::Dashboard;
    }

    /** @param list<Block> $blocks */
    public function blocks(array $blocks): static { $this->blocks = self::listOf($blocks, Block::class, 'blocks'); return $this; }

    public function validate(): void
    {
        $this->blocks !== [] || throw new InvalidDefinition("dashboard {$this->title}: blocks() is required");
    }

    protected function body(RenderContext $rc): array
    {
        return ['grid' => 24, 'blocks' => self::render($this->blocks, $rc)];
    }
}
