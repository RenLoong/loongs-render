<?php

declare(strict_types=1);

namespace Loongs\Render\Builder;

use Loongs\Render\Component\Action;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Field;
use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\PageType;
use Loongs\Render\RenderContext;
use Loongs\Render\Support\Placeholder;

/**
 * Table page (RENDER.md §6.3). Request: page, page_size, sort, order + search fields; response
 * {list,total,page,page_size}. Mobile: card list with the ->mobile() columns only (if none is marked,
 * the first three visible columns), no multi-select. ->tree() / ->paginate(false): the API returns
 * every row at once (a tree or a list), no page / page_size and no pager.
 */
class Table extends Builder
{
    public const MOBILE_FALLBACK_COLUMNS = 3;

    private ?string $api = null;
    private string $rowKey = 'id';
    /** @var list<Field> */
    private array $search = [];
    /** @var list<Column> */
    private array $columns = [];
    /** @var list<Action> */
    private array $toolbar = [];
    /** @var list<Action> */
    private array $rowActions = [];
    private ?bool $selection = null;
    /** @var list<int> */
    private array $pageSizes = [20, 50, 100];
    private ?array $defaultSort = null;
    private ?array $tree = null;
    private bool $paginate = true;
    private ?array $notice = null;

    public function type(): PageType
    {
        return PageType::Table;
    }

    public function api(string $api): static { $this->api = self::path($api, 'table api'); return $this; }
    public function rowKey(string $field): static { $this->rowKey = self::name($field, 'table rowKey'); return $this; }
    /** @param list<Field> $fields */
    public function search(array $fields): static { $this->search = self::listOf($fields, Field::class, 'search'); return $this; }
    /** @param list<Column> $columns */
    public function columns(array $columns): static { $this->columns = self::listOf($columns, Column::class, 'columns'); return $this; }
    /** @param list<Action> $actions */
    public function toolbar(array $actions): static { $this->toolbar = self::listOf($actions, Action::class, 'toolbar'); return $this; }
    /** @param list<Action> $actions */
    public function rowActions(array $actions): static { $this->rowActions = self::listOf($actions, Action::class, 'rowActions'); return $this; }
    /** Multi-select (default: on when the toolbar has a batchRequest the user may run). */
    public function selection(bool $on = true): static { $this->selection = $on; return $this; }

    /** @param list<int> $sizes */
    public function pageSizes(array $sizes): static
    {
        foreach ($sizes as $s) {
            (is_int($s) && $s >= 1 && $s <= 1000) || throw new InvalidDefinition('pageSizes must be integers 1..1000');
        }
        $this->pageSizes = $sizes !== [] ? array_values($sizes) : throw new InvalidDefinition('pageSizes must not be empty');

        return $this;
    }

    public function defaultSort(string $field, string $order = 'desc'): static
    {
        in_array($order, ['asc', 'desc'], true) || throw new InvalidDefinition('defaultSort order must be asc | desc');
        $this->defaultSort = ['field' => self::name($field, 'defaultSort'), 'order' => $order];

        return $this;
    }

    /**
     * Tree table (RENDER.md §6.3): the API returns the whole tree (a list of nodes with
     * [$children]); rows are shown indented in [$column] (default: the first column), no pager.
     * [$expanded]: initial state of parent rows (the client may let users collapse / expand).
     */
    public function tree(string $children = 'children', ?string $column = null, bool $expanded = true): static
    {
        $this->tree = self::compact([
            'children' => self::name($children, 'table tree children'),
            'column' => $column !== null ? self::name($column, 'table tree column') : null,
            'expanded' => $expanded,
        ]);
        $this->paginate = false;

        return $this;
    }

    /** false: the API returns every row at once (a plain list or {list}); no page / page_size, no pager. */
    public function paginate(bool $on = true): static
    {
        $this->tree === null || $on === false || throw new InvalidDefinition("table {$this->title}: a tree table is never paginated");
        $this->paginate = $on;

        return $this;
    }

    /**
     * Notice shown above the table. [$message] may use {field} placeholders filled from the object
     * returned by [$api] (e.g. '来源：{files} · 版本 {hash}'); without $api it is static text.
     */
    public function notice(string $message, ?string $api = null, string $type = 'info'): static
    {
        in_array($type, ['info', 'success', 'warning', 'error'], true) || throw new InvalidDefinition('table notice type: info | success | warning | error');
        Placeholder::assert($message, "table {$this->title} notice");
        $this->notice = self::compact(['message' => $message, 'api' => $api !== null ? self::path($api, 'table notice api') : null, 'type' => $type]);

        return $this;
    }

    public function validate(): void
    {
        $this->api !== null || throw new InvalidDefinition("table {$this->title}: api() is required");
        $this->columns !== [] || throw new InvalidDefinition("table {$this->title}: columns() is required");
        $seen = [];
        foreach ($this->columns as $c) {
            isset($seen[$c->field]) && throw new InvalidDefinition("table {$this->title}: duplicate column {$c->field}");
            $seen[$c->field] = true;
        }
        if ($this->tree !== null && isset($this->tree['column']) && !isset($seen[$this->tree['column']])) {
            throw new InvalidDefinition("table {$this->title}: tree column {$this->tree['column']} is not a column");
        }
    }

    protected function body(RenderContext $rc): array
    {
        $columns = $this->columns;
        if ($rc->mobile) {
            $marked = array_values(array_filter($columns, static fn (Column $c): bool => $c->isMobile));
            $columns = $marked !== [] ? $marked : null;
        }
        $rendered = self::render($columns ?? $this->columns, $rc);
        if ($columns === null) {
            $rendered = array_slice($rendered, 0, self::MOBILE_FALLBACK_COLUMNS);
        }
        $toolbar = self::render($this->toolbar, $rc);
        $hasBatch = array_filter($toolbar, static fn (array $a): bool => $a['action'] === 'batchRequest') !== [];
        $selection = !$rc->mobile && ($this->selection ?? $hasBatch);

        return self::compact([
            'api' => $this->api,
            'rowKey' => $this->rowKey,
            'search' => self::render($this->search, $rc),
            'columns' => $rendered,
            'toolbar' => $toolbar,
            'rowActions' => self::render($this->rowActions, $rc),
            'selection' => $selection,
            'pageSizes' => $this->paginate ? $this->pageSizes : null,
            'defaultSort' => $this->defaultSort,
            'pagination' => $this->paginate ? null : false,
            'tree' => $this->tree,
            'notice' => $this->notice,
        ]);
    }
}
