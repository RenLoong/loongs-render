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
 * Table column and detail / descriptions item (display components of RENDER.md §7).
 *
 *   Column::text('username', '用户名')->sortable()->fixed('left')->mobile()
 *   Column::tag('status', '状态')->enum(AdminStatus::class)
 *   Column::money('amount', '金额')->format('{value|money:CNY}')
 *
 * On mobile, tables only keep columns marked ->mobile() (card list, RENDER.md §6.3).
 */
final class Column extends Node
{
    private bool $sortable = false;
    private ?string $fixed = null;
    private ?int $width = null;
    private ?string $align = null;
    private ?array $options = null;
    private ?string $format = null;
    private ?string $href = null;
    private bool $mobile = false;
    private ?int $span = null;
    private ?string $resource = null;
    private ?string $emptyText = null;

    private function __construct(
        public private(set) string $component,
        public private(set) string $field,
        public private(set) string $label,
    ) {
        self::name($field, "column {$field}");
    }

    public static function text(string $field, string $label): self { return new self('text', $field, $label); }
    public static function tag(string $field, string $label): self { return new self('tag', $field, $label); }
    public static function badge(string $field, string $label): self { return new self('badge', $field, $label); }
    public static function datetime(string $field, string $label): self { return new self('datetime', $field, $label); }
    public static function money(string $field, string $label): self { return new self('money', $field, $label); }
    public static function image(string $field, string $label): self { return new self('image', $field, $label); }
    public static function copyable(string $field, string $label): self { return new self('copyable', $field, $label); }
    public static function progress(string $field, string $label): self { return new self('progress', $field, $label); }
    public static function bool(string $field, string $label): self { return new self('bool', $field, $label); }

    /** $href: relative path or menu route with {placeholders}, e.g. '/tenant/detail?id={id}' */
    public static function link(string $field, string $label, string $href): self
    {
        $c = new self('link', $field, $label);
        $c->href = self::path($href, "column {$field} href");

        return $c;
    }

    public function sortable(bool $sortable = true): self { $this->sortable = $sortable; return $this; }
    public function width(int $px): self
    {
        if ($px < 24 || $px > 2000) {
            throw new InvalidDefinition("column {$this->field}: width must be 24..2000");
        }
        $this->width = $px;

        return $this;
    }
    public function fixed(string $side): self
    {
        $this->fixed = in_array($side, ['left', 'right'], true) ? $side : throw new InvalidDefinition("column {$this->field}: fixed must be left | right");

        return $this;
    }
    public function align(string $align): self
    {
        $this->align = in_array($align, ['left', 'center', 'right'], true) ? $align : throw new InvalidDefinition("column {$this->field}: align must be left | center | right");

        return $this;
    }
    /** Show this column in the mobile card list. */
    public function mobile(bool $mobile = true): self { $this->mobile = $mobile; return $this; }
    /** descriptions item width in columns */
    public function span(int $span): self { $this->span = self::spanOf($span, "column {$this->field}", 4); return $this; }
    public function resource(string $resource): self { $this->resource = $resource; return $this; }
    public function emptyText(string $text): self { $this->emptyText = $text; return $this; }

    /** @param class-string<BackedEnum> $enum */
    public function enum(string $enum): self { $this->options = Options::fromEnum($enum, "column {$this->field}"); return $this; }

    /** @param array<array-key, mixed> $options */
    public function options(array $options): self { $this->options = Options::from($options, "column {$this->field}"); return $this; }

    /**
     * datetime: date pattern (yyyy-MM-dd HH:mm); others: template with {value} and filters, e.g.
     * '{value|thousands}', '{value|percent:1}', '{value|money:CNY}', '{value} 人'.
     */
    public function format(string $format): self
    {
        $this->format = $this->component === 'datetime'
            ? (preg_match('/^[yMdHhms\-\/:. ]{1,32}$/', $format) === 1 ? $format : throw new InvalidDefinition("column {$this->field}: invalid date pattern \"{$format}\""))
            : Placeholder::assert($format, "column {$this->field} format", ['value'], true);

        return $this;
    }

    public bool $isMobile {
        get => $this->mobile;
    }

    public function toArray(RenderContext $rc): ?array
    {
        if (!$this->visible($rc) || !$rc->supports('column', $this->component)) {
            return null;
        }
        $access = $rc->access($this->resource, $this->field);
        if ($access === FieldAccess::Hidden) {
            return null;
        }

        return self::compact([
            'field' => $this->field,
            'label' => $this->label,
            'component' => $this->component,
            'sortable' => $this->sortable ?: null,
            'fixed' => $rc->mobile ? null : $this->fixed,
            'width' => $rc->mobile ? null : $this->width,
            'align' => $this->align,
            'options' => $this->options,
            'format' => $this->format,
            'href' => $this->href,
            'masked' => $access === FieldAccess::Masked ?: null,
            'mobile' => $this->mobile ?: null,
            'span' => $rc->mobile ? null : $this->span,
            'emptyText' => $this->emptyText,
        ]);
    }
}
