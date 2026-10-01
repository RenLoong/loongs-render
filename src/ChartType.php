<?php

declare(strict_types=1);

namespace Loongs\Render;

/** Chart kinds of the renderer-neutral chart description (RENDER.md §6.1.1). */
enum ChartType: string
{
    case Line = 'line';
    case Bar = 'bar';
    case StackedBar = 'stackedBar';
    case Area = 'area';
    case Pie = 'pie';
    case Donut = 'donut';
    case Radar = 'radar';
    case Scatter = 'scatter';
    case Gauge = 'gauge';
    case Combo = 'combo';
    case Custom = 'custom';

    /** exactly one series */
    public function singleSeries(): bool
    {
        return in_array($this, [self::Pie, self::Donut, self::Gauge], true);
    }

    /** has x / y axes (dual axis, reference lines) */
    public function cartesian(): bool
    {
        return in_array($this, [self::Line, self::Bar, self::StackedBar, self::Area, self::Scatter, self::Combo], true);
    }
}
