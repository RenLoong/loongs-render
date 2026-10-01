<?php

declare(strict_types=1);

namespace Fixture\Pages;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Custom;
use Loongs\Render\CustomPage;
use Loongs\Render\PageContext;

#[AsPage('admin.system.role-permission', permission: 'system:role:perm')]
final class RolePermPage extends CustomPage
{
    public function build(PageContext $ctx): Custom
    {
        return Custom::make('角色权限')->component('role-permission-editor')->config(['api' => '/admin/api/roles/{id}/permissions'])->pcOnly();
    }
}
