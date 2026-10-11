<?php

declare(strict_types=1);

namespace Loongs\Render\Component;

use Loongs\Render\ChartType;
use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\RenderContext;
use Loongs\Render\Support\Color;
use Loongs\Render\Support\Placeholder;

/**
 * Renderer-neutral chart description (RENDER.md §6.1.1). The client translates it to fl_chart (Flutter)
 * or uCharts (uni-app). Formats are placeholder templates only — never executable code:
 *   {value} {series} {x} {name} {percent}, filters |thousands |percent:N |fixed:N |money:CNY |date:MM-dd
 *
 *   Chart::line('近30天开通')->api('/uuims/api/stats/opens')
 *       ->x('date', type: 'time', format: 'MM-dd')
 *       ->series('count', '开通数', color: '#2563eb', area: true, smooth: true)
 *       ->series('closed', '停用数', color: '#ef4444', dashed: true, axis: 'right')
 *       ->yAxis('left', min: 0)->yAxis('right', min: 0, format: '{value}%')
 *       ->legend('top')->tooltip(shared: true, format: '{series}: {value}')
 *       ->markLine(100, '目标', color: '#16a34a')->height(320);
 */
final class Chart extends Block
{
    public const X_TYPES = ['category', 'time', 'value'];
    public const LEGEND = ['top', 'bottom', 'left', 'right', 'none'];
    public const COMBO_SERIES = ['line', 'bar', 'area', 'scatter'];
    public const FORMAT_VARS = ['value', 'series', 'x', 'name', 'percent'];

    /**
     * Native renderer options that may be passed through (RENDER.md §6.1.1 "白名单字段"). Values must be
     * scalars or lists of scalars. Extend with Chart::allowRendererOption().
     */
    public const RENDERER_OPTIONS = [
        'fl_chart' => ['barWidth', 'barRadius', 'lineWidth', 'dotSize', 'showDots', 'curveSmoothness', 'gridHorizontal', 'gridVertical',
            'borderVisible', 'centerSpaceRadius', 'sectionsSpace', 'pieRadius', 'animationMs', 'minX', 'maxX', 'interval'],
        'ucharts' => ['barWidth', 'lineWidth', 'dataPointShape', 'dataLabel', 'enableScroll', 'itemCount', 'rotateLabel', 'ringWidth',
            'activeRadius', 'animation', 'padding', 'splitNumber', 'gridType', 'dashLength', 'labelCount'],
    ];

    /** @var array<string, list<string>> */
    private static array $extraRendererOptions = [];

    private ?string $api = null;
    private ?array $x = null;
    /** @var list<array<string, mixed>> */
    private array $series = [];
    /** @var array<string, array<string, mixed>> */
    private array $yAxis = [];
    private ?string $legend = null;
    private ?array $tooltip = null;
    /** @var list<array<string, mixed>> */
    private array $markLines = [];
    /** @var list<array<string, mixed>> */
    private array $markAreas = [];
    private ?array $palette = null;
    private ?int $height = null;
    private ?string $emptyText = null;
    private ?array $labels = null;
    /** @var array<string, array<string, mixed>> */
    private array $renderer = [];
    private ?string $customName = null;
    private ?array $customConfig = null;

    private function __construct(public private(set) ChartType $type, ?string $title)
    {
        parent::__construct('chart');
        if ($title !== null) {
            $this->title($title);
        }
    }

    public static function line(?string $title = null): self { return new self(ChartType::Line, $title); }
    public static function bar(?string $title = null): self { return new self(ChartType::Bar, $title); }
    public static function stackedBar(?string $title = null): self { return new self(ChartType::StackedBar, $title); }
    public static function area(?string $title = null): self { return new self(ChartType::Area, $title); }
    public static function pie(?string $title = null): self { return new self(ChartType::Pie, $title); }
    public static function donut(?string $title = null): self { return new self(ChartType::Donut, $title); }
    public static function radar(?string $title = null): self { return new self(ChartType::Radar, $title); }
    public static function scatter(?string $title = null): self { return new self(ChartType::Scatter, $title); }
    public static function gauge(?string $title = null): self { return new self(ChartType::Gauge, $title); }
    /** Mixed series: every ->series() needs type: line | bar | area | scatter. */
    public static function combo(?string $title = null): self { return new self(ChartType::Combo, $title); }

    public static function of(ChartType|string $type, ?string $title = null): self
    {
        $t = $type instanceof ChartType ? $type : (ChartType::tryFrom($type) ?? throw new InvalidDefinition("unknown chart type \"{$type}\""));
        if ($t === ChartType::Custom) {
            throw new InvalidDefinition('use Chart::custom(name, config) for custom charts');
        }

        return new self($t, $title);
    }

    /**
     * A chart component registered by the client under $name (RENDER.md §6.1.1 / §7 fallback when the
     * client does not know it). $config must be plain JSON data.
     */
    public static function custom(string $name, array $config = [], ?string $title = null): self
    {
        $c = new self(ChartType::Custom, $title);
        $c->customName = preg_match('/^[a-z][a-z0-9-]{0,47}$/', $name) === 1 ? $name : throw new InvalidDefinition("custom chart name must be [a-z][a-z0-9-], got \"{$name}\"");
        $c->customConfig = self::json($config, "custom chart {$name}");

        return $c;
    }

    public static function allowRendererOption(string $renderer, string ...$keys): void
    {
        self::$extraRendererOptions[$renderer] = array_values(array_unique([...self::$extraRendererOptions[$renderer] ?? [], ...$keys]));
    }

    public function api(string $api): self { $this->api = self::path($api, "chart {$this->title}"); return $this; }

    /** x axis / category field. format: date pattern for time, else a {value} template. */
    public function x(string $field, string $type = 'category', ?string $format = null, ?string $name = null): self
    {
        if (!in_array($type, self::X_TYPES, true)) {
            throw new InvalidDefinition("chart {$this->title}: x type must be " . implode(' | ', self::X_TYPES));
        }
        if ($format !== null) {
            $type === 'time'
                ? (preg_match('/^[yMdHhms\-\/:. ]{1,32}$/', $format) === 1 || throw new InvalidDefinition("chart {$this->title}: invalid x date format \"{$format}\""))
                : Placeholder::assert($format, "chart {$this->title} x format", ['value'], true);
        }
        $this->x = self::compact(['field' => self::name($field, "chart {$this->title} x"), 'type' => $type, 'format' => $format, 'name' => $name]);

        return $this;
    }

    public function series(
        string $field,
        ?string $name = null,
        ?string $color = null,
        bool $area = false,
        bool $smooth = false,
        bool $dashed = false,
        string $axis = 'left',
        ?string $type = null,
        ?string $stack = null,
        ?bool $labels = null,
        ?string $format = null,
    ): self {
        $w = "chart {$this->title} series {$field}";
        if (!in_array($axis, ['left', 'right'], true)) {
            throw new InvalidDefinition("{$w}: axis must be left | right");
        }
        if ($type !== null && !in_array($type, self::COMBO_SERIES, true)) {
            throw new InvalidDefinition("{$w}: type must be " . implode(' | ', self::COMBO_SERIES));
        }
        foreach ($this->series as $s) {
            if ($s['field'] === $field) {
                throw new InvalidDefinition("{$w}: duplicate series");
            }
        }
        $this->series[] = self::compact([
            'field' => self::name($field, $w),
            'name' => $name,
            'type' => $type,
            'color' => $color !== null ? Color::assert($color, $w) : null,
            'area' => $area ?: null,
            'smooth' => $smooth ?: null,
            'dashed' => $dashed ?: null,
            'axis' => $axis === 'right' ? 'right' : null,
            'stack' => $stack !== null ? self::name($stack, "{$w} stack") : null,
            'labels' => $labels,
            'format' => $format !== null ? Placeholder::assert($format, "{$w} format", self::FORMAT_VARS, true) : null,
        ]);

        return $this;
    }

    public function yAxis(string $side = 'left', int|float|null $min = null, int|float|null $max = null, ?string $format = null, ?string $name = null): self
    {
        if (!in_array($side, ['left', 'right'], true)) {
            throw new InvalidDefinition("chart {$this->title}: yAxis side must be left | right");
        }
        if ($min !== null && $max !== null && $min >= $max) {
            throw new InvalidDefinition("chart {$this->title}: yAxis {$side} min must be < max");
        }
        $this->yAxis[$side] = self::compact(['min' => $min, 'max' => $max, 'name' => $name,
            'format' => $format !== null ? Placeholder::assert($format, "chart {$this->title} yAxis {$side}", ['value'], true) : null]);

        return $this;
    }

    public function legend(string $position = 'top'): self
    {
        $this->legend = in_array($position, self::LEGEND, true) ? $position : throw new InvalidDefinition("chart {$this->title}: legend must be " . implode(' | ', self::LEGEND));

        return $this;
    }

    public function tooltip(bool $shared = true, ?string $format = null): self
    {
        $this->tooltip = self::compact(['shared' => $shared,
            'format' => $format !== null ? Placeholder::assert($format, "chart {$this->title} tooltip", self::FORMAT_VARS, true) : null]);

        return $this;
    }

    /** Data labels on points / bars / slices. */
    public function labels(bool $show = true, ?string $format = null): self
    {
        $this->labels = self::compact(['show' => $show,
            'format' => $format !== null ? Placeholder::assert($format, "chart {$this->title} labels", self::FORMAT_VARS, true) : null]);

        return $this;
    }

    /** Reference line on a y axis (or the x axis with axis: 'x'). */
    public function markLine(int|float|string $value, ?string $label = null, ?string $color = null, string $axis = 'left', bool $dashed = true): self
    {
        $this->markLines[] = self::compact(['value' => $value, 'label' => $label, 'axis' => $this->markAxis($axis),
            'color' => $color !== null ? Color::assert($color, "chart {$this->title} markLine") : null, 'dashed' => $dashed]);

        return $this;
    }

    /** Highlighted range, e.g. markArea('2026-09-01', '2026-09-07', '活动期', axis: 'x'). */
    public function markArea(int|float|string $from, int|float|string $to, ?string $label = null, ?string $color = null, string $axis = 'x'): self
    {
        $this->markAreas[] = self::compact(['from' => $from, 'to' => $to, 'label' => $label, 'axis' => $this->markAxis($axis),
            'color' => $color !== null ? Color::assert($color, "chart {$this->title} markArea") : null]);

        return $this;
    }

    /** @param list<string> $colors */
    public function palette(array $colors): self
    {
        if ($colors === [] || !array_is_list($colors)) {
            throw new InvalidDefinition("chart {$this->title}: palette must be a non-empty list");
        }
        $this->palette = array_map(fn (string $c): string => Color::assert($c, "chart {$this->title} palette"), $colors);

        return $this;
    }

    public function height(int $px): self
    {
        $this->height = $px >= 80 && $px <= 1200 ? $px : throw new InvalidDefinition("chart {$this->title}: height must be 80..1200");

        return $this;
    }

    public function emptyText(string $text): self { $this->emptyText = $text; return $this; }

    /**
     * Native options of one renderer (fl_chart | ucharts | registered via allowRendererOption()). Only
     * whitelisted keys with scalar / list-of-scalar values; clients ignore what they don't know.
     */
    public function renderer(string $renderer, array $options): self
    {
        $allowed = [...self::RENDERER_OPTIONS[$renderer] ?? [], ...self::$extraRendererOptions[$renderer] ?? []];
        if ($allowed === []) {
            throw new InvalidDefinition("chart {$this->title}: unknown renderer \"{$renderer}\" (" . implode(' | ', array_keys(self::RENDERER_OPTIONS)) . ')');
        }
        foreach ($options as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                throw new InvalidDefinition("chart {$this->title}: renderer {$renderer} option \"{$k}\" is not whitelisted");
            }
            $ok = is_scalar($v) || $v === null || (is_array($v) && array_is_list($v) && array_filter($v, static fn ($i) => !is_scalar($i)) === []);
            if (!$ok) {
                throw new InvalidDefinition("chart {$this->title}: renderer {$renderer}.{$k} must be a scalar or list of scalars");
            }
        }
        $this->renderer[$renderer] = [...$this->renderer[$renderer] ?? [], ...$options];

        return $this;
    }

    /** Structural checks that need the whole definition (called when rendering and by PageRegistry::inspect()). */
    public function validate(): void
    {
        $w = "chart {$this->title}";
        if ($this->type === ChartType::Custom) {
            return;
        }
        if ($this->api === null) {
            throw new InvalidDefinition("{$w}: api() is required");
        }
        if ($this->series === []) {
            throw new InvalidDefinition("{$w}: at least one series() is required");
        }
        if ($this->type->singleSeries() && count($this->series) !== 1) {
            throw new InvalidDefinition("{$w}: {$this->type->value} takes exactly one series, got " . count($this->series));
        }
        if ($this->type !== ChartType::Gauge && $this->x === null) {
            throw new InvalidDefinition("{$w}: x() is required for {$this->type->value}");
        }
        foreach ($this->series as $s) {
            if ($this->type === ChartType::Combo && !isset($s['type'])) {
                throw new InvalidDefinition("{$w}: combo series {$s['field']} needs type: " . implode(' | ', self::COMBO_SERIES));
            }
            if ($this->type !== ChartType::Combo && isset($s['type'])) {
                throw new InvalidDefinition("{$w}: series type is only for combo charts");
            }
            if (!$this->type->cartesian() && isset($s['axis'])) {
                throw new InvalidDefinition("{$w}: {$this->type->value} has no right axis");
            }
        }
        if (!$this->type->cartesian() && ($this->yAxis !== [] || $this->markLines !== [] || $this->markAreas !== [])) {
            throw new InvalidDefinition("{$w}: yAxis / markLine / markArea need a cartesian chart");
        }
        foreach ([...$this->markLines, ...$this->markAreas] as $m) {
            if (($m['axis'] ?? 'left') === 'right' && !array_filter($this->series, static fn ($s) => ($s['axis'] ?? null) === 'right')) {
                throw new InvalidDefinition("{$w}: mark on the right axis but no series uses axis: 'right'");
            }
        }
    }

    public function toArray(RenderContext $rc): ?array
    {
        if (!$this->visible($rc) || !$rc->supports('block', 'chart') || !$rc->supports('chart', $this->type->value)) {
            return null;
        }
        $this->validate();

        return self::compact([
            'component' => 'chart',
            'id' => $this->id,
            'title' => $this->title,
            'span' => $rc->mobile ? null : $this->span,
            'refresh' => $this->refresh,
            'chart' => $this->type->value,
            'name' => $this->customName,
            'config' => $this->customConfig === [] ? new \stdClass() : $this->customConfig,
            'api' => $this->api,
            'x' => $this->x,
            'series' => $this->series !== [] ? $this->series : null,
            'yAxis' => $this->yAxis !== [] ? array_map(static fn (array $a): array|object => $a === [] ? new \stdClass() : $a, $this->yAxis) : null,
            'legend' => $this->legend,
            'tooltip' => $this->tooltip,
            'labels' => $this->labels,
            'markLines' => $this->markLines !== [] ? $this->markLines : null,
            'markAreas' => $this->markAreas !== [] ? $this->markAreas : null,
            'palette' => $this->palette,
            'height' => $this->height,
            'emptyText' => $this->emptyText,
            'renderer' => $this->renderer !== [] ? $this->renderer : null,
        ]);
    }

    private function markAxis(string $axis): string
    {
        return in_array($axis, ['left', 'right', 'x'], true) ? $axis : throw new InvalidDefinition("chart {$this->title}: mark axis must be left | right | x");
    }

    private static function json(array $data, string $where): array
    {
        array_walk_recursive($data, static function ($v, $k) use ($where): void {
            if (!is_scalar($v) && $v !== null) {
                throw new InvalidDefinition("{$where}: config.{$k} must be plain JSON data");
            }
        });

        return $data;
    }
}
