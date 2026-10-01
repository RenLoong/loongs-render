<?php

declare(strict_types=1);

namespace Loongs\Render;

use Loongs\Render\Builder\Builder;

/**
 * A page class: builds the description for one page code. Extend TablePage, FormPage, DashboardPage,
 * DetailPage or CustomPage; the page type is fixed by the base class and the build() return type.
 * Pages must not check permissions themselves — use ->can() / FieldPolicy (RENDER.md §4.4).
 */
abstract class Page
{
    abstract public static function type(): PageType;

    abstract public function build(PageContext $ctx): Builder;
}
