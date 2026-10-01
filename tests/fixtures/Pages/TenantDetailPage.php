<?php

declare(strict_types=1);

namespace Fixture\Pages;

use Fixture\AdminStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Detail;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Block;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Tab;
use Loongs\Render\DetailPage;
use Loongs\Render\PageContext;

#[AsPage('admin.tenant.detail', permission: 'tenant:view')]
final class TenantDetailPage extends DetailPage
{
    public function build(PageContext $ctx): Detail
    {
        return Detail::make('租户详情')
            ->resource('tenants')
            ->load('/admin/api/tenants/{id}')
            ->header('{name}', [Column::tag('status', '状态')->enum(AdminStatus::class)], [
                Action::openForm('编辑', 'admin.tenant.form', ['id' => '{id}'])->can('tenant:edit'),
            ])
            ->tabs([
                Tab::make('基本信息', [Block::descriptions([
                    Column::text('name', '名称'),
                    Column::copyable('code', '编码'),
                    Column::money('balance', '余额')->format('{value|money:CNY}'),
                    Column::link('owner', '负责人', '/system/admins?id={owner_id}')->span(2),
                ], 3)]),
                Tab::make('套餐记录', [Block::table('admin.tenant.plan-logs', ['tenant_id' => '{id}'])]),
                Tab::make('操作日志', [Block::timeline('/admin/api/tenants/{id}/logs')])->can('tenant:logs'),
            ])
            ->platforms('pc', 'mobile');
    }
}
