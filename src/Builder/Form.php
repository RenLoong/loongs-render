<?php

declare(strict_types=1);

namespace Loongs\Render\Builder;

use Loongs\Render\Component\Action;
use Loongs\Render\Component\Field;
use Loongs\Render\Component\Section;
use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\PageType;
use Loongs\Render\RenderContext;

/**
 * Form page (RENDER.md §6.2), also used as dialog / drawer. One page serves create + edit: with an id
 * parameter the client calls load, otherwise uses defaults; submit can differ per mode.
 * Mobile: single column.
 */
class Form extends Builder
{
    public const LAYOUTS = ['vertical', 'horizontal', 'inline'];

    private ?string $load = null;
    /** @var array<string, array{method: string, api: string}> mode (default|create|update) => submit */
    private array $submit = [];
    private string $layout = 'vertical';
    private int $columns = 1;
    /** @var list<Section> */
    private array $sections = [];
    /** @var list<Action>|null */
    private ?array $actions = null;

    public function type(): PageType
    {
        return PageType::Form;
    }

    public function load(string $api): static { $this->load = self::path($api, 'form load'); return $this; }

    /** @param 'create'|'update'|null $mode null = both */
    public function submit(string $method, string $api, ?string $mode = null): static
    {
        $m = strtoupper($method);
        in_array($m, ['POST', 'PUT', 'PATCH'], true) || throw new InvalidDefinition("form {$this->title}: submit method must be POST | PUT | PATCH");
        in_array($mode, [null, 'create', 'update'], true) || throw new InvalidDefinition("form {$this->title}: submit mode must be create | update");
        $this->submit[$mode ?? 'default'] = ['method' => $m, 'api' => self::path($api, 'form submit')];

        return $this;
    }

    public function layout(string $layout): static
    {
        $this->layout = in_array($layout, self::LAYOUTS, true) ? $layout : throw new InvalidDefinition('form layout must be ' . implode(' | ', self::LAYOUTS));

        return $this;
    }

    public function columns(int $columns): static { $this->columns = self::spanOf($columns, 'form columns', 4); return $this; }

    /** @param list<Section> $sections */
    public function sections(array $sections): static { $this->sections = self::listOf($sections, Section::class, 'sections'); return $this; }

    /** Shortcut for one untitled section. @param list<Field> $fields */
    public function fields(array $fields): static { $this->sections = [Section::make(null, $fields)]; return $this; }

    /** Default: [Action::submit(), Action::close()] @param list<Action> $actions */
    public function actions(array $actions): static { $this->actions = self::listOf($actions, Action::class, 'actions'); return $this; }

    public function validate(): void
    {
        $this->submit !== [] || throw new InvalidDefinition("form {$this->title}: submit() is required");
        $this->sections !== [] || throw new InvalidDefinition("form {$this->title}: sections() or fields() is required");
        $seen = [];
        foreach ($this->sections as $s) {
            foreach ($s->fields as $f) {
                isset($seen[$f->name]) && throw new InvalidDefinition("form {$this->title}: duplicate field {$f->name}");
                $seen[$f->name] = true;
            }
        }
    }

    protected function body(RenderContext $rc): array
    {
        $submit = isset($this->submit['default']) && count($this->submit) === 1 ? $this->submit['default']
            : ['create' => $this->submit['create'] ?? $this->submit['default'] ?? null, 'update' => $this->submit['update'] ?? $this->submit['default'] ?? null];

        return self::compact([
            'load' => $this->load,
            'submit' => self::compact($submit),
            'layout' => $rc->mobile ? 'vertical' : $this->layout,
            'columns' => $rc->mobile ? 1 : $this->columns,
            'sections' => self::render($this->sections, $rc),
            'actions' => self::render($this->actions ?? [Action::submit(), Action::close()], $rc),
        ]);
    }
}
