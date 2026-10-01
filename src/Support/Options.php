<?php

declare(strict_types=1);

namespace Loongs\Render\Support;

use BackedEnum;
use Loongs\Render\Contract\OptionEnum;
use Loongs\Render\Exception\InvalidDefinition;

/** Normalises option sources (static list / key => label map / backed enum) to list<{value,label,color?}>. */
final class Options
{
    /**
     * @param class-string<BackedEnum>|array<array-key, mixed> $source
     * @return list<array{value: int|string, label: string, color?: string}>
     */
    public static function from(string|array $source, string $where): array
    {
        if (is_string($source)) {
            return self::fromEnum($source, $where);
        }
        $out = [];
        if (array_is_list($source) && ($source === [] || is_array($source[0]))) {
            foreach ($source as $i => $o) {
                if (!is_array($o) || !array_key_exists('value', $o) || !isset($o['label']) || !(is_int($o['value']) || is_string($o['value']))) {
                    throw new InvalidDefinition("{$where}: options[{$i}] needs scalar value + label");
                }
                $row = ['value' => $o['value'], 'label' => (string) $o['label']];
                if (isset($o['color'])) {
                    $row['color'] = Color::assert((string) $o['color'], "{$where}: options[{$i}]");
                }
                $out[] = $row;
            }

            return $out;
        }
        foreach ($source as $value => $label) {
            $out[] = ['value' => $value, 'label' => (string) $label];
        }

        return $out;
    }

    /**
     * @param class-string<BackedEnum> $enum
     * @return list<array{value: int|string, label: string, color?: string}>
     */
    public static function fromEnum(string $enum, string $where = 'options'): array
    {
        if (!enum_exists($enum) || !is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidDefinition("{$where}: {$enum} is not a backed enum");
        }
        $out = [];
        foreach ($enum::cases() as $case) {
            $row = ['value' => $case->value, 'label' => $case instanceof OptionEnum ? $case->label() : $case->name];
            if ($case instanceof OptionEnum && ($c = $case->color()) !== null) {
                $row['color'] = Color::assert($c, "{$where}: {$enum}::{$case->name}");
            }
            $out[] = $row;
        }

        return $out;
    }
}
