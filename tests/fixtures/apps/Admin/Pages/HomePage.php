<?php

declare(strict_types=1);

namespace App\Admin\Pages;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Dashboard;
use Loongs\Render\Component\Block;
use Loongs\Render\DashboardPage;
use Loongs\Render\PageContext;

#[AsPage('admin.home')]
final class HomePage extends DashboardPage
{
    public function build(PageContext $ctx): Dashboard
    {
        return Dashboard::make('首页')->blocks([Block::markdown('欢迎')]);
    }
}
