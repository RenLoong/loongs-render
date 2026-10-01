<?php

declare(strict_types=1);

namespace Fixture\Bad;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Dashboard;
use Loongs\Render\DashboardPage;
use Loongs\Render\PageContext;

#[AsPage('Admin/Bad')]
final class BadCodePage extends DashboardPage
{
    public function build(PageContext $ctx): Dashboard
    {
        return Dashboard::make('x');
    }
}
