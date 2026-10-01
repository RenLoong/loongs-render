<?php

declare(strict_types=1);

namespace Loongs\Render\Component;

use BackedEnum;
use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\FieldAccess;
use Loongs\Render\RenderContext;
use Loongs\Render\Support\Options;
use Loongs\Render\Support\Placeholder;

/**
 * Form / search field (RENDER.md §5.2, input components of §7).
 *
 *   Field::text('mobile', '手机号')->required()->regex('^1\d{10}$', '手机号格式不正确')->props(['maxLength' => 11])
 *   Field::select('status', '状态')->options(AdminStatus::class)
 *   Field::select('role_ids', '角色')->multiple()->optionsApi('/admin/api/roles/options')
 */
final class Field extends Node
{
    public const RULES = ['required', 'regex', 'min', 'max', 'minLength', 'maxLength', 'email', 'url', 'in', 'integer', 'numeric'];
    private const OPTION_COMPONENTS = ['select', 'radio', 'checkbox', 'treeSelect', 'cascader'];

    private bool $required = false;
    private bool $readonly = false;
    private ?string $placeholder = null;
    private mixed $default = null;
    /** @var list<array{rule: string, value?: mixed, message?: string}> */
    private array $rules = [];
    /** @var array<string, mixed> */
    private array $props = [];
    private ?array $visibleWhen = null;
    private ?int $span = null;
    private ?array $options = null;
    private ?array $optionsApi = null;
    private bool $multiple = false;
    private ?string $resource = null;
    private ?string $help = null;

    private function __construct(
        public private(set) string $component,
        public private(set) string $name,
        public private(set) string $label,
    ) {
        self::name($name, "field {$name}");
    }

    public static function text(string $name, string $label): self { return new self('text', $name, $label); }
    public static function textarea(string $name, string $label): self { return new self('textarea', $name, $label); }
    public static function password(string $name, string $label): self { return new self('password', $name, $label); }
    public static function number(string $name, string $label): self { return new self('number', $name, $label); }
    public static function money(string $name, string $label): self { return new self('money', $name, $label); }
    public static function select(string $name, string $label): self { return new self('select', $name, $label); }
    public static function radio(string $name, string $label): self { return new self('radio', $name, $label); }
    public static function checkbox(string $name, string $label): self { return new self('checkbox', $name, $label); }
    public static function switch(string $name, string $label): self { return new self('switch', $name, $label); }
    public static function date(string $name, string $label): self { return new self('date', $name, $label); }
    public static function datetime(string $name, string $label): self { return new self('datetime', $name, $label); }
    public static function dateRange(string $name, string $label): self { return new self('dateRange', $name, $label); }
    public static function time(string $name, string $label): self { return new self('time', $name, $label); }
    public static function treeSelect(string $name, string $label): self { return new self('treeSelect', $name, $label); }
    public static function cascader(string $name, string $label): self { return new self('cascader', $name, $label); }
    /** P2: needs the file service */
    public static function upload(string $name, string $label): self { return new self('upload', $name, $label); }
    /** P2: needs the file service */
    public static function image(string $name, string $label): self { return new self('image', $name, $label); }
    /** later; not rendered on mobile (ComponentCatalog) */
    public static function richText(string $name, string $label): self { return new self('richText', $name, $label); }
    /** read-only JSON viewer */
    public static function json(string $name, string $label): self { return (new self('json', $name, $label))->readonly(); }

    public function required(bool $required = true): self { $this->required = $required; return $this; }
    public function readonly(bool $readonly = true): self { $this->readonly = $readonly; return $this; }
    public function placeholder(string $text): self { $this->placeholder = $text; return $this; }
    public function default(mixed $value): self
    {
        if (!is_scalar($value) && !is_array($value) && $value !== null) {
            throw new InvalidDefinition("field {$this->name}: default must be JSON (scalar / array / null)");
        }
        $this->default = $value;

        return $this;
    }
    public function help(string $text): self { $this->help = $text; return $this; }
    public function span(int $span): self { $this->span = self::spanOf($span, "field {$this->name}"); return $this; }
    public function multiple(bool $multiple = true): self { $this->multiple = $multiple; return $this; }
    /** field-permission resource of this field (default: the builder's ->resource()) */
    public function resource(string $resource): self { $this->resource = $resource; return $this; }

    /** @param array<string, scalar|list<scalar>|null> $props renderer hints, JSON only (e.g. maxLength, rows, min, max, step) */
    public function props(array $props): self
    {
        array_walk_recursive($props, function ($v, $k): void {
            if (!is_scalar($v) && $v !== null) {
                throw new InvalidDefinition("field {$this->name}: props.{$k} must be JSON");
            }
        });
        $this->props = [...$this->props, ...$props];

        return $this;
    }

    public function rule(string $rule, mixed $value = null, ?string $message = null): self
    {
        if (!in_array($rule, self::RULES, true)) {
            throw new InvalidDefinition("field {$this->name}: unknown rule \"{$rule}\" (" . implode(', ', self::RULES) . ')');
        }
        if ($rule === 'regex' && (!is_string($value) || !self::validRegex($value))) {
            throw new InvalidDefinition("field {$this->name}: invalid regex");
        }
        if (in_array($rule, ['min', 'max', 'minLength', 'maxLength'], true) && !is_int($value) && !is_float($value)) {
            throw new InvalidDefinition("field {$this->name}: rule {$rule} needs a number");
        }
        if ($rule === 'in' && !is_array($value)) {
            throw new InvalidDefinition("field {$this->name}: rule in needs a list");
        }
        $this->rules[] = self::compactRule(['rule' => $rule, 'value' => $value, 'message' => $message]);

        return $this;
    }

    public function regex(string $pattern, ?string $message = null): self { return $this->rule('regex', $pattern, $message); }
    public function min(int|float $v, ?string $message = null): self { return $this->rule('min', $v, $message); }
    public function max(int|float $v, ?string $message = null): self { return $this->rule('max', $v, $message); }
    public function length(?int $min = null, ?int $max = null, ?string $message = null): self
    {
        if ($min !== null) {
            $this->rule('minLength', $min, $message);
        }

        return $max !== null ? $this->rule('maxLength', $max, $message) : $this;
    }
    public function email(?string $message = null): self { return $this->rule('email', null, $message); }

    /**
     * Static options, a key => label map, or a backed enum (expanded to {value,label,color}).
     * treeSelect / cascader take nested [{value,label,children:[…]}].
     *
     * @param class-string<BackedEnum>|array<array-key, mixed> $options
     */
    public function options(string|array $options): self
    {
        $this->requireOptions();
        $this->options = in_array($this->component, ['treeSelect', 'cascader'], true) && is_array($options)
            ? self::tree($options, "field {$this->name}")
            : Options::from($options, "field {$this->name}");

        return $this;
    }

    /** Options loaded from an API (`?keyword=&page=` when searchable). */
    public function optionsApi(string $api, bool $searchable = false): self
    {
        $this->requireOptions();
        $this->optionsApi = ['api' => self::path($api, "field {$this->name}"), 'searchable' => $searchable ?: null];

        return $this;
    }

    /** Show only when another field matches: op eq | in | notEmpty (no scripts). */
    public function visibleWhen(string $field, string $op, mixed $value = null): self
    {
        self::name($field, "field {$this->name} visibleWhen");
        $this->visibleWhen = match ($op) {
            'eq' => is_scalar($value) || $value === null ? ['field' => $field, 'eq' => $value] : throw new InvalidDefinition("field {$this->name}: visibleWhen eq needs a scalar"),
            'in' => is_array($value) && array_is_list($value) ? ['field' => $field, 'in' => $value] : throw new InvalidDefinition("field {$this->name}: visibleWhen in needs a list"),
            'notEmpty' => ['field' => $field, 'notEmpty' => true],
            default => throw new InvalidDefinition("field {$this->name}: visibleWhen op must be eq | in | notEmpty"),
        };

        return $this;
    }

    public function toArray(RenderContext $rc): ?array
    {
        if (!$this->visible($rc) || !$rc->supports('field', $this->component)) {
            return null;
        }
        $access = $rc->access($this->resource, $this->name);
        if ($access === FieldAccess::Hidden) {
            return null;
        }
        $readonly = $this->readonly || $access === FieldAccess::Readonly || $access === FieldAccess::Masked;
        if ($this->placeholder !== null) {
            Placeholder::assert($this->placeholder, "field {$this->name} placeholder");
        }

        return self::compact([
            'name' => $this->name,
            'label' => $this->label,
            'component' => $this->component,
            'required' => $this->required && !$readonly ? true : null,
            'readonly' => $readonly ?: null,
            'masked' => $access === FieldAccess::Masked ?: null,
            'placeholder' => $this->placeholder,
            'default' => $this->default,
            'help' => $this->help,
            'multiple' => $this->multiple ?: null,
            'options' => $this->options,
            'optionsApi' => $this->optionsApi !== null ? self::compact($this->optionsApi) : null,
            'rules' => $this->rules !== [] ? $this->rules : null,
            'props' => $this->props !== [] ? $this->props : null,
            'visibleWhen' => $this->visibleWhen,
            'span' => $rc->mobile ? null : $this->span,   // mobile: single column
        ]);
    }

    private function requireOptions(): void
    {
        if (!in_array($this->component, self::OPTION_COMPONENTS, true)) {
            throw new InvalidDefinition("field {$this->name}: {$this->component} has no options (only " . implode(', ', self::OPTION_COMPONENTS) . ')');
        }
    }

    /** JS-compatible pattern check without tripping host error handlers. */
    private static function validRegex(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match('/' . str_replace('/', '\/', $pattern) . '/u', '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private static function compactRule(array $r): array
    {
        return array_filter($r, static fn ($v): bool => $v !== null);
    }

    private static function tree(array $nodes, string $where): array
    {
        $out = [];
        foreach ($nodes as $i => $n) {
            if (!is_array($n) || !array_key_exists('value', $n) || !isset($n['label'])) {
                throw new InvalidDefinition("{$where}: options[{$i}] needs value + label");
            }
            $row = ['value' => $n['value'], 'label' => (string) $n['label']];
            if (!empty($n['children'])) {
                $row['children'] = self::tree((array) $n['children'], "{$where} > {$n['label']}");
            }
            $out[] = $row;
        }

        return $out;
    }
}
