<?php

declare(strict_types=1);

namespace Loongs\Render;

use Loongs\Render\Builder\Dashboard;

/** Base of dashboard pages (page_type: dashboard). */
abstract class DashboardPage extends Page
{
    final public static function type(): PageType
    {
        return PageType::Dashboard;
    }

    abstract public function build(PageContext $ctx): Dashboard;
}
