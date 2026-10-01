<?php

declare(strict_types=1);

namespace Fixture\Pages;

use Fixture\AdminStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Form;
use Loongs\Render\Component\Field;
use Loongs\Render\Component\Section;
use Loongs\Render\FormPage;
use Loongs\Render\PageContext;

#[AsPage('admin.system.admin-form', permission: ['system:admin:create', 'system:admin:edit'], version: 'v3')]
final class AdminFormPage extends FormPage
{
    public function build(PageContext $ctx): Form
    {
        $edit = $ctx->param('id') !== null;

        return Form::make($edit ? '编辑管理员' : '新增管理员')
            ->resource('admins')
            ->load('/admin/api/admins/{id}')
            ->submit('POST', '/admin/api/admins', 'create')
            ->submit('PUT', '/admin/api/admins/{id}', 'update')
            ->columns(2)
            ->sections([
                Section::make('基本信息', [
                    Field::text('username', '用户名')->required()->length(3, 32)->span(12),
                    Field::text('mobile', '手机号')->regex('^1\d{10}$', '手机号格式不正确')->props(['maxLength' => 11])->span(12),
                    Field::text('salary', '薪资')->span(12),
                    Field::password('password', '密码')->help('留空不修改')->visibleWhen('type', 'in', ['local']),
                    Field::radio('status', '状态')->options(AdminStatus::class)->default(1),
                    Field::richText('intro', '简介'),
                ]),
                Section::make('角色', [
                    Field::select('role_ids', '角色')->multiple()->optionsApi('/admin/api/roles/options', searchable: true),
                ])->can('system:role'),
            ]);
    }
}
