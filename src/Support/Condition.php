<?php

declare(strict_types=1);

namespace Loongs\Render\Support;

use Loongs\Render\Exception\InvalidDefinition;

/**
 * Declarative condition (field name validated by the caller) on another value (no scripts): {field, eq} | {field, in} | {field, notEmpty}.
 * Used by Field::visibleWhen (other form values) and Action::visibleWhen / hiddenWhen (current row / record).
 */
final class Condition
{
    public const OPS = ['eq', 'in', 'notEmpty'];

    /** @return array{field: string, eq?: scalar|null, in?: list<mixed>, notEmpty?: true} */
    public static function make(string $where, string $kind, string $field, string $op, mixed $value = null): array
    {
        return match ($op) {
            'eq' => is_scalar($value) || $value === null ? ['field' => $field, 'eq' => $value] : throw new InvalidDefinition("{$where}: {$kind} eq needs a scalar"),
            'in' => is_array($value) && array_is_list($value) ? ['field' => $field, 'in' => $value] : throw new InvalidDefinition("{$where}: {$kind} in needs a list"),
            'notEmpty' => ['field' => $field, 'notEmpty' => true],
            default => throw new InvalidDefinition("{$where}: {$kind} op must be eq | in | notEmpty"),
        };
    }
}
