<?php

declare(strict_types=1);

namespace Loongs\Render;

use Loongs\Render\Builder\Table;

/** Base of table pages (page_type: table). */
abstract class TablePage extends Page
{
    final public static function type(): PageType
    {
        return PageType::Table;
    }

    abstract public function build(PageContext $ctx): Table;
}
