<?php

declare(strict_types=1);

namespace Fixture\Dup;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Dashboard;
use Loongs\Render\Component\Block;
use Loongs\Render\DashboardPage;
use Loongs\Render\PageContext;

#[AsPage('admin.overview')]
final class DupPage extends DashboardPage
{
    public function build(PageContext $ctx): Dashboard
    {
        return Dashboard::make('dup')->blocks([Block::markdown('x')]);
    }
}
