<?php

declare(strict_types=1);

namespace Loongs\Render\Validation;

use Loongs\Render\Schema;
use stdClass;

/**
 * Small JSON Schema validator for the subset used by Schema::jsonSchema(): type, enum, const, required,
 * properties, additionalProperties, minProperties, items, minItems, pattern, minimum, maximum,
 * $ref (#/$defs/…), allOf, anyOf, not, if/then/else. Not a general-purpose implementation.
 */
final class SchemaValidator
{
    private stdClass $root;

    public function __construct(?array $schema = null)
    {
        $this->root = json_decode(json_encode($schema ?? Schema::jsonSchema(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param mixed $data a description array (as returned by PageRegistry::render()) or decoded JSON
     * @return list<string> errors as "<json pointer>: message"; [] = valid
     */
    public function validate(mixed $data): array
    {
        $data = json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $errors = [];
        $this->check($this->root, $data, '', $errors);

        return $errors;
    }

    public function isValid(mixed $data): bool
    {
        return $this->validate($data) === [];
    }

    /** @param list<string> $errors */
    private function check(stdClass $s, mixed $v, string $path, array &$errors): void
    {
        $at = $path === '' ? '/' : $path;
        if (isset($s->{'$ref'})) {
            $this->check($this->resolve($s->{'$ref'}), $v, $path, $errors);
        }
        if (isset($s->type)) {
            $types = (array) $s->type;
            $ok = false;
            foreach ($types as $t) {
                $ok = $ok || self::isType($v, $t);
            }
            if (!$ok) {
                $errors[] = "{$at}: expected " . implode('|', $types) . ', got ' . self::typeOf($v);

                return;
            }
        }
        if (property_exists($s, 'const') && $v != $s->const) {
            $errors[] = "{$at}: must be " . json_encode($s->const);
        }
        if (isset($s->enum) && !in_array($v, $s->enum, true)) {
            $errors[] = "{$at}: " . json_encode($v, JSON_UNESCAPED_UNICODE) . ' not in ' . json_encode($s->enum);
        }
        if (is_string($v)) {
            if (isset($s->pattern) && preg_match('~' . str_replace('~', '\~', $s->pattern) . '~u', $v) !== 1) {
                $errors[] = "{$at}: " . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . " does not match {$s->pattern}";
            }
        }
        if (is_int($v) || is_float($v)) {
            if (isset($s->minimum) && $v < $s->minimum) {
                $errors[] = "{$at}: {$v} < minimum {$s->minimum}";
            }
            if (isset($s->maximum) && $v > $s->maximum) {
                $errors[] = "{$at}: {$v} > maximum {$s->maximum}";
            }
        }
        if (is_array($v)) {
            if (isset($s->minItems) && count($v) < $s->minItems) {
                $errors[] = "{$at}: needs at least {$s->minItems} items";
            }
            if (isset($s->items) && $s->items instanceof stdClass) {
                foreach ($v as $i => $item) {
                    $this->check($s->items, $item, "{$path}/{$i}", $errors);
                }
            }
        }
        if ($v instanceof stdClass) {
            $props = isset($s->properties) ? get_object_vars($s->properties) : [];
            foreach ($s->required ?? [] as $r) {
                if (!property_exists($v, $r)) {
                    $errors[] = "{$at}: missing required \"{$r}\"";
                }
            }
            if (isset($s->minProperties) && count(get_object_vars($v)) < $s->minProperties) {
                $errors[] = "{$at}: needs at least {$s->minProperties} properties";
            }
            foreach (get_object_vars($v) as $k => $item) {
                if (isset($props[$k])) {
                    $this->check($props[$k], $item, "{$path}/{$k}", $errors);
                } elseif (isset($s->additionalProperties)) {
                    if ($s->additionalProperties === false) {
                        $errors[] = "{$at}: unexpected property \"{$k}\"";
                    } elseif ($s->additionalProperties instanceof stdClass) {
                        $this->check($s->additionalProperties, $item, "{$path}/{$k}", $errors);
                    }
                }
            }
        }
        foreach ($s->allOf ?? [] as $sub) {
            $this->check($sub, $v, $path, $errors);
        }
        if (isset($s->anyOf)) {
            $ok = false;
            foreach ($s->anyOf as $sub) {
                $e = [];
                $this->check($sub, $v, $path, $e);
                $ok = $ok || $e === [];
            }
            $ok || $errors[] = "{$at}: matches none of anyOf";
        }
        if (isset($s->not)) {
            $e = [];
            $this->check($s->not, $v, $path, $e);
            $e === [] && $errors[] = "{$at}: must not match \"not\" schema";
        }
        if (isset($s->if)) {
            $e = [];
            $this->check($s->if, $v, $path, $e);
            if ($e === [] && isset($s->then)) {
                $this->check($s->then, $v, $path, $errors);
            } elseif ($e !== [] && isset($s->else)) {
                $this->check($s->else, $v, $path, $errors);
            }
        }
    }

    private function resolve(string $ref): stdClass
    {
        if (!str_starts_with($ref, '#/$defs/') || !isset($this->root->{'$defs'}->{substr($ref, 8)})) {
            throw new \InvalidArgumentException("unsupported \$ref {$ref}");
        }

        return $this->root->{'$defs'}->{substr($ref, 8)};
    }

    private static function isType(mixed $v, string $t): bool
    {
        return match ($t) {
            'object' => $v instanceof stdClass,
            'array' => is_array($v),
            'string' => is_string($v),
            'integer' => is_int($v) || (is_float($v) && floor($v) === $v),
            'number' => is_int($v) || is_float($v),
            'boolean' => is_bool($v),
            'null' => $v === null,
            default => false,
        };
    }

    private static function typeOf(mixed $v): string
    {
        return match (true) {
            $v instanceof stdClass => 'object',
            is_array($v) => 'array',
            default => get_debug_type($v),
        };
    }
}
