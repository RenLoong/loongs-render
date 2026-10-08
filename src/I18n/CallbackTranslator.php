<?php

declare(strict_types=1);

namespace Loongs\Render\I18n;

use Closure;
use Loongs\Render\Contract\Translator;

/** Translator from a closure: fn (string $text, string $locale): ?string — or a [locale => [text => translation]] map. */
final readonly class CallbackTranslator implements Translator
{
    /** @var Closure(string, string): ?string */
    private Closure $fn;

    /** @param (Closure(string, string): ?string)|array<string, array<string, string>> $source */
    public function __construct(Closure|array $source)
    {
        $this->fn = $source instanceof Closure ? $source : static fn (string $text, string $locale): ?string => $source[$locale][$text] ?? null;
    }

    public function translate(string $text, string $locale): ?string
    {
        return ($this->fn)($text, $locale);
    }
}
