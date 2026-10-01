<?php

declare(strict_types=1);

namespace Fixture\Pages;

use Fixture\AdminStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Table;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Field;
use Loongs\Render\PageContext;
use Loongs\Render\TablePage;

#[AsPage('admin.system.admins', permission: 'system:admin')]
final class AdminsPage extends TablePage
{
    public function build(PageContext $ctx): Table
    {
        return Table::make('管理员')
            ->breadcrumb(['系统管理', '管理员'])
            ->resource('admins')
            ->api('/admin/api/admins')
            ->rowKey('id')
            ->search([
                Field::text('keyword', '用户名/姓名'),
                Field::select('status', '状态')->options(AdminStatus::class),
                Field::dateRange('created_at', '创建时间'),
            ])
            ->columns([
                Column::text('username', '用户名')->sortable()->fixed('left')->width(160)->mobile(),
                Column::text('mobile', '手机号'),
                Column::text('email', '邮箱'),
                Column::tag('status', '状态')->enum(AdminStatus::class)->mobile(),
                Column::datetime('created_at', '创建时间')->format('yyyy-MM-dd HH:mm'),
            ])
            ->toolbar([
                Action::openForm('新增', 'admin.system.admin-form')->can('system:admin:create')->primary(),
                Action::batchRequest('批量禁用', 'POST', '/admin/api/admins/disable')->can('system:admin:edit')->confirm('确定禁用选中的管理员？'),
            ])
            ->rowActions([
                Action::openForm('编辑', 'admin.system.admin-form', ['id' => '{id}'], 'drawer')->can('system:admin:edit'),
                Action::request('删除', 'DELETE', '/admin/api/admins/{id}')->confirm('确定删除 {username}？')->can('system:admin:delete')->danger(),
            ])
            ->defaultSort('id', 'desc');
    }
}
