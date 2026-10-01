<?php

declare(strict_types=1);

namespace Loongs\Render;

use Loongs\Render\Builder\Custom;

/** Base of custom pages (page_type: custom). */
abstract class CustomPage extends Page
{
    final public static function type(): PageType
    {
        return PageType::Custom;
    }

    abstract public function build(PageContext $ctx): Custom;
}
