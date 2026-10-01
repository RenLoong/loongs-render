<?php

declare(strict_types=1);

namespace Loongs\Render\Support;

use Loongs\Render\Exception\InvalidDefinition;

/**
 * Text templates with `{field}` placeholders (RENDER.md §5.1), replaced by the client with the current
 * row / record / route parameter. No expressions: a placeholder is a field path, nothing else.
 *
 *   name      [A-Za-z_][A-Za-z0-9_]* with optional .segments   e.g. {id} {dept.name}
 *   filter    only in chart / number formats: {value|thousands} {value|percent:1} {value|money:CNY}
 *             {value|fixed:2} {x|date:MM-dd}
 *   literal   "{{" and "}}" are a literal brace
 */
final class Placeholder
{
    public const NAME = '[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*';

    /** filter => argument regex ('' = no argument) */
    public const FILTERS = [
        'thousands' => '',
        'percent' => '\d',
        'fixed' => '\d',
        'money' => '[A-Z]{3}',
        'date' => '[yMdHhms\-\/:. ]{1,32}',
    ];

    /** @return list<string> placeholder names in order of appearance (filters stripped) */
    public static function names(string $template): array
    {
        preg_match_all('/(?<!\{)\{(' . self::NAME . ')(?:\|[^{}]*)?\}(?!\})/', $template, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @param list<string>|null $allowed placeholder names that may be used (null = any field path)
     * @throws InvalidDefinition with $where in the message
     */
    public static function assert(string $template, string $where, ?array $allowed = null, bool $filters = false): string
    {
        $rest = str_replace(['{{', '}}'], '', $template);
        $token = '/\{(' . self::NAME . ')(?:\|([a-z]+)(?::([^{}]*))?)?\}/';
        $stripped = preg_replace_callback($token, static function (array $m) use ($where, $allowed, $filters, $template): string {
            if ($allowed !== null && !in_array($m[1], $allowed, true)) {
                throw new InvalidDefinition(sprintf('%s: placeholder {%s} not allowed in "%s" (allowed: %s)', $where, $m[1], $template, implode(', ', array_map(static fn ($n) => '{' . $n . '}', $allowed))));
            }
            if (isset($m[2]) && $m[2] !== '') {
                if (!$filters || !array_key_exists($m[2], self::FILTERS)) {
                    throw new InvalidDefinition(sprintf('%s: filter "|%s" not allowed in "%s"%s', $where, $m[2], $template, $filters ? ' (allowed: ' . implode(', ', array_keys(self::FILTERS)) . ')' : ''));
                }
                $argRe = self::FILTERS[$m[2]];
                $arg = $m[3] ?? '';
                if (($argRe === '' && $arg !== '') || ($argRe !== '' && $arg !== '' && preg_match('/^' . $argRe . '$/', $arg) !== 1)) {
                    throw new InvalidDefinition(sprintf('%s: bad argument "%s" for filter |%s in "%s"', $where, $arg, $m[2], $template));
                }
            }

            return '';
        }, $rest);
        if (str_contains((string) $stripped, '{') || str_contains((string) $stripped, '}')) {
            throw new InvalidDefinition(sprintf('%s: malformed placeholder in "%s" (use {field}; literal braces as {{ }})', $where, $template));
        }

        return $template;
    }

    /** Server-side substitution (e.g. tests, breadcrumbs from route params). Unknown names → empty string. */
    public static function render(string $template, array $data): string
    {
        $out = preg_replace_callback('/(?<!\{)\{(' . self::NAME . ')(?:\|[^{}]*)?\}(?!\})/', static function (array $m) use ($data): string {
            $v = $data;
            foreach (explode('.', $m[1]) as $seg) {
                if (!is_array($v) || !array_key_exists($seg, $v)) {
                    return '';
                }
                $v = $v[$seg];
            }

            return is_scalar($v) ? (string) $v : '';
        }, $template);

        return str_replace(['{{', '}}'], ['{', '}'], (string) $out);
    }
}
