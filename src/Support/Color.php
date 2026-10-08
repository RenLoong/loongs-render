<?php

declare(strict_types=1);

namespace Loongs\Render\Support;

use Loongs\Render\Exception\InvalidDefinition;

/**
 * Colors (RENDER.md §6.1.1 / theme):
 *   - "#rgb" / "#rrggbb" / "#rrggbbaa" (lower-cased) — the same in light and dark mode;
 *   - a semantic token resolved by the client for its current theme: primary, secondary, success,
 *     warning, danger, info, muted, default, chart1 … chart8 (the client's categorical palette);
 *   - a light/dark pair "light|dark" (each a hex color or token), e.g. "#16a34a|#4ade80".
 * Prefer tokens: they follow the client's theme (light / dark) and accent automatically.
 */
final class Color
{
    public const TOKENS = ['primary', 'secondary', 'success', 'warning', 'danger', 'info', 'muted', 'default',
        'chart1', 'chart2', 'chart3', 'chart4', 'chart5', 'chart6', 'chart7', 'chart8'];

    private const HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    public static function assert(string $color, string $where): string
    {
        if (str_contains($color, '|')) {
            $parts = explode('|', $color);
            if (count($parts) === 2 && self::single($parts[0]) !== null && self::single($parts[1]) !== null) {
                return self::single($parts[0]) . '|' . self::single($parts[1]);
            }
        } elseif (($c = self::single($color)) !== null) {
            return $c;
        }
        throw new InvalidDefinition(sprintf('%s: invalid color "%s" (use #rgb, #rrggbb, #rrggbbaa, %s or a "light|dark" pair)', $where, $color, implode(' / ', self::TOKENS)));
    }

    /** A light/dark pair from two colors (hex or tokens). */
    public static function pair(string $light, string $dark): string
    {
        return self::assert($light . '|' . $dark, 'Color::pair');
    }

    /** @return list<string> the default categorical palette: chart1 … chart8 (themed by the client) */
    public static function palette(): array
    {
        return ['chart1', 'chart2', 'chart3', 'chart4', 'chart5', 'chart6', 'chart7', 'chart8'];
    }

    private static function single(string $c): ?string
    {
        $c = trim($c);
        if (preg_match(self::HEX, $c) === 1) {
            return strtolower($c);
        }

        return in_array($c, self::TOKENS, true) ? $c : null;
    }
}
