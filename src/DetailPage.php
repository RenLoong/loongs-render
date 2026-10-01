<?php

declare(strict_types=1);

namespace Loongs\Render;

use Loongs\Render\Builder\Detail;

/** Base of detail pages (page_type: detail). */
abstract class DetailPage extends Page
{
    final public static function type(): PageType
    {
        return PageType::Detail;
    }

    abstract public function build(PageContext $ctx): Detail;
}
