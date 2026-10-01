<?php

declare(strict_types=1);

namespace Loongs\Render\Component;

use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\PageCode;
use Loongs\Render\RenderContext;
use Loongs\Render\Support\Placeholder;

/**
 * Dashboard / detail blocks and layout containers (RENDER.md §6, §7): stat, rank, shortcuts,
 * descriptions, embedded table page, timeline, markdown, alert, card, collapse, grid. Charts: Chart.
 * Every block can ->can(), ->span() (of the 24-column grid; dropped on mobile = stacked),
 * ->refresh(seconds) and ->id() (target of Action::refresh()).
 */
class Block extends Node
{
    /** @var array<string, mixed> */
    protected array $props = [];
    /** @var list<Node> */
    protected array $children = [];
    protected string $childKey = 'blocks';
    protected ?string $id = null;
    protected ?string $title = null;
    protected ?int $span = null;
    protected ?int $refresh = null;

    protected function __construct(public private(set) string $component)
    {
    }

    /** Single number: {api} → data[$field]; trend: mom | yoy | a field holding the change ratio. */
    public static function stat(string $title, string $api, string $field): self
    {
        $b = (new self('stat'))->title($title);
        $b->props = ['api' => self::path($api, "stat {$title}"), 'field' => self::name($field, "stat {$title}")];

        return $b;
    }

    public function trend(string $trend): static
    {
        $this->props['trend'] = in_array($trend, ['mom', 'yoy'], true) ? $trend : self::name($trend, "block {$this->title} trend");

        return $this;
    }

    /** Number format template, e.g. '{value|thousands}', '{value|money:CNY}', '{value}%'. */
    public function format(string $format): static
    {
        $this->props['format'] = Placeholder::assert($format, "block {$this->title} format", ['value'], true);

        return $this;
    }

    /** Ranking list: rows of {api} with label / value fields. */
    public static function rank(string $title, string $api, string $label, string $value, int $limit = 10): self
    {
        $b = (new self('rank'))->title($title);
        $b->props = ['api' => self::path($api, "rank {$title}"), 'label' => self::name($label, "rank {$title}"), 'value' => self::name($value, "rank {$title}"),
            'limit' => max(1, min(100, $limit))];

        return $b;
    }

    /** Quick entries; each item is an Action (label, icon, action). */
    public static function shortcuts(array $actions, ?string $title = null): self
    {
        $b = new self('shortcuts');
        $b->title = $title;
        foreach ($actions as $a) {
            $a instanceof Action || throw new InvalidDefinition('shortcuts: items must be Action');
        }
        $b->children = array_values($actions);
        $b->childKey = 'items';

        return $b;
    }

    /** Description list of Column items (detail pages). Mobile: one column. */
    public static function descriptions(array $items, int $columns = 3, ?string $title = null): self
    {
        $b = new self('descriptions');
        $b->title = $title;
        foreach ($items as $i) {
            $i instanceof Column || throw new InvalidDefinition('descriptions: items must be Column');
        }
        $b->children = array_values($items);
        $b->childKey = 'items';
        $b->props['columns'] = self::spanOf($columns, 'descriptions columns', 4);

        return $b;
    }

    /**
     * Embeds another table page (its own permission + columns apply when the client loads it).
     *
     * @param array<string, scalar|null> $params e.g. ['tenant_id' => '{id}']
     */
    public static function table(string $page, array $params = [], ?string $title = null): self
    {
        $b = new self('table');
        $b->title = $title;
        $b->props = ['page' => PageCode::assert($page), 'params' => $params !== [] ? self::params($params, "table {$page}") : null];

        return $b;
    }

    public static function timeline(string $api, string $time = 'time', string $content = 'content', ?string $title = null): self
    {
        $b = new self('timeline');
        $b->title = $title;
        $b->props = ['api' => self::path($api, 'timeline'), 'time' => self::name($time, 'timeline'), 'content' => self::name($content, 'timeline')];

        return $b;
    }

    /** Markdown text (rendered as text by the client, no HTML). */
    public static function markdown(string $content, ?string $title = null): self
    {
        $b = new self('markdown');
        $b->title = $title;
        $b->props = ['content' => $content];

        return $b;
    }

    public static function alert(string $message, string $type = 'info', ?string $title = null): self
    {
        $b = new self('alert');
        $b->title = $title;
        $b->props = ['type' => in_array($type, ['info', 'success', 'warning', 'error'], true) ? $type : throw new InvalidDefinition('alert type: info | success | warning | error'),
            'message' => Placeholder::assert($message, 'alert')];

        return $b;
    }

    /** @param list<Block> $blocks */
    public static function card(string $title, array $blocks): self
    {
        return (new self('card'))->title($title)->withChildren($blocks);
    }

    /** @param list<Block> $blocks nested 24-column grid */
    public static function grid(array $blocks): self
    {
        return (new self('grid'))->withChildren($blocks);
    }

    /** @param list<Tab> $panels collapsible panels (title + blocks) */
    public static function collapse(array $panels): self
    {
        $b = new self('collapse');
        foreach ($panels as $p) {
            $p instanceof Tab || throw new InvalidDefinition('collapse: panels must be Tab');
        }
        $b->children = array_values($panels);
        $b->childKey = 'panels';

        return $b;
    }

    public function id(string $id): static { $this->id = self::name($id, 'block id'); return $this; }
    public function title(string $title): static { $this->title = Placeholder::assert($title, "block {$title}"); return $this; }
    public function span(int $span): static { $this->span = self::spanOf($span, "block {$this->title}"); return $this; }

    /** Auto refresh in seconds (≥ 5). */
    public function refresh(int $seconds): static
    {
        $this->refresh = $seconds >= 5 ? $seconds : throw new InvalidDefinition("block {$this->title}: refresh must be ≥ 5 seconds");

        return $this;
    }

    /** @param list<Block> $blocks */
    protected function withChildren(array $blocks): static
    {
        foreach ($blocks as $b) {
            $b instanceof self || throw new InvalidDefinition("{$this->component}: children must be Block");
        }
        $this->children = array_values($blocks);

        return $this;
    }

    public function toArray(RenderContext $rc): ?array
    {
        if (!$this->visible($rc) || !$rc->supports('block', $this->component)) {
            return null;
        }
        $props = $this->props;
        if ($rc->mobile && $this->component === 'descriptions') {
            $props['columns'] = 1;
        }
        $children = $this->children !== [] ? self::render($this->children, $rc) : null;
        if ($this->component === 'shortcuts' && $children !== null) {
            // RENDER.md §6.1: items [{title, icon?, action: {action, ...}}]
            $children = array_map(static function (array $a): array {
                $title = $a['label'];
                $icon = $a['icon'] ?? null;
                unset($a['label'], $a['icon']);

                return self::compact(['title' => $title, 'icon' => $icon, 'action' => $a]);
            }, $children);
        }
        if ($this->children !== [] && $children === [] && in_array($this->component, ['card', 'grid', 'collapse', 'shortcuts', 'descriptions'], true)) {
            return null;   // container left empty by permissions / platform → drop it too
        }

        return self::compact([
            'component' => $this->component,
            'id' => $this->id,
            'title' => $this->title,
            'span' => $rc->mobile ? null : $this->span,
            'refresh' => $this->refresh,
            ...self::compact($props),
            ...($children !== null ? [$this->childKey => $children] : []),
        ]);
    }
}
