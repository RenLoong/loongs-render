<?php

declare(strict_types=1);

namespace Loongs\Render\I18n;

use Loongs\Render\Contract\Translator;

/**
 * Walks a page description (or any part of it) and translates its user-facing strings:
 *   - TEXT_KEYS anywhere (title, label, message, placeholder, help, confirm, emptyText, format,
 *     okText, cancelText, description, text, unit, prefix, suffix);
 *   - "name" except on form fields (where it is the submitted field name, nodes with "component" +
 *     "label");
 *   - "content" of markdown / alert blocks;
 *   - breadcrumb items.
 * Data-ish keys (field, api, value, icon, id, color …) are never touched. Format strings keep their
 * {placeholders}; a pack translates the whole template ("{value} 人" → "{value} people").
 */
final class DescriptionTranslator
{
    public const TEXT_KEYS = ['title', 'label', 'message', 'placeholder', 'help', 'confirm', 'emptyText', 'format',
        'okText', 'cancelText', 'description', 'text', 'unit', 'prefix', 'suffix'];

    private const TEXT_CONTENT = ['markdown', 'alert'];

    /** @var array<string, true> */
    private array $textKeys;

    public function __construct()
    {
        $this->textKeys = array_fill_keys(self::TEXT_KEYS, true);
    }

    /**
     * @param array<mixed> $node
     * @return array<mixed>
     */
    public function translate(array $node, Translator $translator, string $locale): array
    {
        return $this->walk($node, static fn (string $s): string => $translator->translate($s, $locale) ?? $s);
    }

    /**
     * Every translatable string in $node (unique, in order) — for "missing translation" checks.
     *
     * @param array<mixed> $node
     * @return list<string>
     */
    public function texts(array $node): array
    {
        $seen = [];
        $this->walk($node, static function (string $s) use (&$seen): string {
            $seen[$s] = true;

            return $s;
        });

        return array_map('strval', array_keys($seen));
    }

    /**
     * @param array<mixed> $node
     * @param \Closure(string): string $fn
     * @return array<mixed>
     */
    private function walk(array $node, \Closure $fn): array
    {
        $isField = isset($node['component'], $node['label']) && is_string($node['component']) && !isset($node['chart']);
        $contentIsText = isset($node['component']) && in_array($node['component'], self::TEXT_CONTENT, true);
        foreach ($node as $k => $v) {
            if (is_string($v)) {
                if ($v !== '' && is_string($k) && (isset($this->textKeys[$k]) || ($k === 'name' && !$isField) || ($k === 'content' && $contentIsText))) {
                    $node[$k] = $fn($v);
                }
            } elseif (is_array($v)) {
                if ($k === 'breadcrumb' || ($k === 'tags' && array_is_list($v) && $v !== [] && is_string($v[0]))) {
                    $node[$k] = array_map(static fn (mixed $s): mixed => is_string($s) && $s !== '' ? $fn($s) : $s, $v);
                } else {
                    $node[$k] = $this->walk($v, $fn);
                }
            }
        }

        return $node;
    }
}
