<?php

declare(strict_types=1);

namespace Loongs\Render\Support;

use Loongs\Render\Exception\InvalidDefinition;

/** Colors are #rgb, #rrggbb or #rrggbbaa (lower-cased), or a theme token such as "primary" / "danger". */
final class Color
{
    public const TOKENS = ['primary', 'secondary', 'success', 'warning', 'danger', 'info', 'muted', 'default'];

    public static function assert(string $color, string $where): string
    {
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color) === 1) {
            return strtolower($color);
        }
        if (in_array($color, self::TOKENS, true)) {
            return $color;
        }
        throw new InvalidDefinition(sprintf('%s: invalid color "%s" (use #rgb, #rrggbb, #rrggbbaa or %s)', $where, $color, implode(' / ', self::TOKENS)));
    }
}
