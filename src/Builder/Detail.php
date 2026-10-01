<?php

declare(strict_types=1);

namespace Loongs\Render\Builder;

use Loongs\Render\Component\Action;
use Loongs\Render\Component\Block;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Tab;
use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\PageType;
use Loongs\Render\RenderContext;
use Loongs\Render\Support\Placeholder;

/** Detail page (RENDER.md §6.4): load + header (title template, tags, actions) + tabs or blocks. */
class Detail extends Builder
{
    private ?string $load = null;
    private ?string $headerTitle = null;
    /** @var list<Column> */
    private array $tags = [];
    /** @var list<Action> */
    private array $headerActions = [];
    /** @var list<Tab> */
    private array $tabs = [];
    /** @var list<Block> */
    private array $blocks = [];

    public function type(): PageType
    {
        return PageType::Detail;
    }

    public function load(string $api): static { $this->load = self::path($api, 'detail load'); return $this; }

    /**
     * @param list<Column> $tags   usually Column::tag(...)->enum(...)
     * @param list<Action> $actions
     */
    public function header(string $title, array $tags = [], array $actions = []): static
    {
        $this->headerTitle = Placeholder::assert($title, 'detail header');
        $this->tags = self::listOf($tags, Column::class, 'header tags');
        $this->headerActions = self::listOf($actions, Action::class, 'header actions');

        return $this;
    }

    /** @param list<Tab> $tabs */
    public function tabs(array $tabs): static { $this->tabs = self::listOf($tabs, Tab::class, 'tabs'); return $this; }
    /** @param list<Block> $blocks */
    public function blocks(array $blocks): static { $this->blocks = self::listOf($blocks, Block::class, 'blocks'); return $this; }

    public function validate(): void
    {
        $this->load !== null || throw new InvalidDefinition("detail {$this->title}: load() is required");
        ($this->tabs === []) !== ($this->blocks === []) || throw new InvalidDefinition("detail {$this->title}: use either tabs() or blocks()");
    }

    protected function body(RenderContext $rc): array
    {
        $header = $this->headerTitle === null ? null : self::compact([
            'title' => $this->headerTitle,
            'tags' => self::render($this->tags, $rc) ?: null,
            'actions' => self::render($this->headerActions, $rc) ?: null,
        ]);

        return self::compact([
            'load' => $this->load,
            'header' => $header,
            'tabs' => $this->tabs !== [] ? self::render($this->tabs, $rc) : null,
            'blocks' => $this->blocks !== [] ? self::render($this->blocks, $rc) : null,
        ]);
    }
}
