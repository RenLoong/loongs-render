<?php

declare(strict_types=1);

namespace Loongs\Render\Contract;

/**
 * Optional interface for backed enums used as options / tags (RENDER.md §7: one PHP enum →
 * {value, label, color} for every client). Without it the case name is the label and color is null.
 */
interface OptionEnum
{
    public function label(): string;

    /** #hex or theme token (Support\Color), null = default */
    public function color(): ?string;
}
