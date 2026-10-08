<?php

declare(strict_types=1);

namespace Loongs\Render\Contract;

/**
 * Translates the user-facing texts of a page description (titles, labels, messages, placeholders,
 * option labels, chart names / formats … — see I18n\DescriptionTranslator::TEXT_KEYS) into the
 * client's locale (ClientInfo::$locale). Return null to keep the source text.
 *
 * loongs/render ships no translator of its own: plug in loongs/language
 * (Loongs\Language\Integration\Render\RenderTranslator) or any closure via CallbackTranslator.
 */
interface Translator
{
    public function translate(string $text, string $locale): ?string;
}
