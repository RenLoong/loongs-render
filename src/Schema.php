<?php

declare(strict_types=1);

namespace Loongs\Render;

/**
 * Page description format (RENDER.md §5). VERSION is sent as `schema` in every description; clients
 * send the highest version they understand in X-Render-Schema.
 *
 * jsonSchema() exports a JSON Schema (draft 2020-12) of the description so frontend test suites
 * (Flutter / uni-app) can validate fixtures; Validation\SchemaValidator checks it in PHP.
 */
final class Schema
{
    public const VERSION = 1;
    public const ID = 'https://github.com/RenLoong/loongs-render/schema/v1.json';

    /** @return array<string, mixed> */
    public static function jsonSchema(): array
    {
        $str = ['type' => 'string'];
        $bool = ['type' => 'boolean'];
        $cond = ['type' => 'object', 'required' => ['field'], 'properties' => ['field' => $str, 'eq' => new \stdClass(), 'in' => ['type' => 'array'], 'notEmpty' => $bool], 'additionalProperties' => false];
        $api = ['type' => 'string', 'pattern' => '^/(?!/)\S*$'];
        $code = ['type' => 'string', 'pattern' => PageCode::JSON_PATTERN];
        $params = ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'null']]];
        $ref = static fn (string $d): array => ['$ref' => "#/\$defs/{$d}"];
        $listOf = static fn (string $d): array => ['type' => 'array', 'items' => ['$ref' => "#/\$defs/{$d}"]];
        $color = ['type' => 'string', 'pattern' => '^(#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|[a-z][a-zA-Z0-9.-]{0,47})$'];
        $when = static fn (string $prop, array|string $values, array $then): array => ['if' => ['properties' => [$prop => is_array($values) ? ['enum' => $values] : ['const' => $values]], 'required' => [$prop]], 'then' => $then];
        $req = static fn (string ...$keys): array => ['required' => $keys];

        $defs = [
            'option' => ['type' => 'object', 'required' => ['value', 'label'], 'properties' => [
                'value' => ['type' => ['string', 'number', 'boolean']], 'label' => $str, 'color' => $color, 'children' => $listOf('option'),
            ], 'additionalProperties' => false],
            'field' => ['type' => 'object', 'required' => ['name', 'label', 'component'], 'additionalProperties' => false, 'properties' => [
                'name' => $str, 'label' => $str, 'component' => ['enum' => ComponentCatalog::FIELDS],
                'required' => $bool, 'readonly' => $bool, 'masked' => $bool, 'multiple' => $bool,
                'placeholder' => $str, 'default' => new \stdClass(), 'help' => $str,
                'options' => $listOf('option'),
                'optionsApi' => ['type' => 'object', 'required' => ['api'], 'properties' => ['api' => $api, 'searchable' => $bool], 'additionalProperties' => false],
                'rules' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['rule'], 'properties' => ['rule' => $str, 'value' => new \stdClass(), 'message' => $str], 'additionalProperties' => false]],
                'props' => ['type' => 'object'],
                'visibleWhen' => $cond,
                'span' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 24],
            ]],
            'column' => ['type' => 'object', 'required' => ['field', 'label', 'component'], 'additionalProperties' => false, 'properties' => [
                'field' => $str, 'label' => $str, 'component' => ['enum' => ComponentCatalog::COLUMNS],
                'sortable' => $bool, 'fixed' => ['enum' => ['left', 'right']], 'width' => ['type' => 'integer'], 'align' => ['enum' => ['left', 'center', 'right']],
                'options' => $listOf('option'), 'format' => $str, 'href' => $api, 'masked' => $bool, 'mobile' => $bool,
                'span' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4], 'emptyText' => $str,
            ]],
            'action' => ['type' => 'object', 'required' => ['label', 'action'], 'additionalProperties' => false, 'properties' => [
                'label' => $str, 'action' => ['enum' => ComponentCatalog::ACTIONS],
                'page' => $code, 'params' => $params, 'mode' => ['enum' => ['dialog', 'drawer', 'page']],
                'path' => $api, 'query' => $params, 'method' => ['enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']], 'api' => $api,
                'body' => ['type' => 'object'], 'target' => $str, 'confirm' => $str, 'primary' => $bool, 'danger' => $bool, 'icon' => $str,
                'then' => ['enum' => ['refresh', 'close', 'navigate', 'toast']], 'thenPath' => $api, 'message' => $str,
                'visibleWhen' => $cond, 'hiddenWhen' => $cond,
            ], 'allOf' => [
                $when('action', ['openForm', 'openDetail'], $req('page')),
                $when('action', ['request', 'batchRequest'], $req('method', 'api')),
                $when('action', 'navigate', $req('path')),
                $when('action', ['export', 'import'], $req('api')),
            ]],
            'shortcut' => ['type' => 'object', 'required' => ['title', 'action'], 'additionalProperties' => false, 'properties' => [
                'title' => $str, 'icon' => $str, 'action' => ['type' => 'object', 'required' => ['action'], 'properties' => ['action' => ['enum' => ComponentCatalog::ACTIONS]]],
            ]],
            'series' => ['type' => 'object', 'required' => ['field'], 'additionalProperties' => false, 'properties' => [
                'field' => $str, 'name' => $str, 'type' => ['enum' => ['line', 'bar', 'area', 'scatter']], 'color' => $color,
                'area' => $bool, 'smooth' => $bool, 'dashed' => $bool, 'axis' => ['enum' => ['left', 'right']], 'stack' => $str, 'labels' => $bool, 'format' => $str,
            ]],
            'mark' => ['type' => 'object', 'properties' => ['value' => ['type' => ['number', 'string']], 'from' => ['type' => ['number', 'string']], 'to' => ['type' => ['number', 'string']],
                'label' => $str, 'color' => $color, 'axis' => ['enum' => ['left', 'right', 'x']], 'dashed' => $bool], 'additionalProperties' => false],
            'chart' => ['type' => 'object', 'required' => ['chart'], 'properties' => [
                'chart' => ['enum' => ComponentCatalog::CHARTS], 'name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]*$'], 'config' => ['type' => 'object'],
                'api' => $api, 'x' => ['type' => 'object', 'required' => ['field', 'type'], 'properties' => ['field' => $str, 'type' => ['enum' => ['category', 'time', 'value']], 'format' => $str, 'name' => $str], 'additionalProperties' => false],
                'series' => ['type' => 'array', 'minItems' => 1, 'items' => $ref('series')],
                'yAxis' => ['type' => 'object', 'properties' => ['left' => ['type' => 'object'], 'right' => ['type' => 'object']], 'additionalProperties' => false],
                'legend' => ['enum' => ['top', 'bottom', 'left', 'right', 'none']],
                'tooltip' => ['type' => 'object', 'properties' => ['shared' => $bool, 'format' => $str], 'additionalProperties' => false],
                'labels' => ['type' => 'object', 'properties' => ['show' => $bool, 'format' => $str], 'additionalProperties' => false],
                'markLines' => $listOf('mark'), 'markAreas' => $listOf('mark'), 'palette' => ['type' => 'array', 'items' => $color],
                'height' => ['type' => 'integer', 'minimum' => 80, 'maximum' => 1200], 'emptyText' => $str,
                'renderer' => ['type' => 'object', 'additionalProperties' => ['type' => 'object']],
            ], 'allOf' => [
                $when('chart', 'custom', $req('name')),
                ['if' => ['properties' => ['chart' => ['not' => ['const' => 'custom']]], 'required' => ['chart']], 'then' => $req('api', 'series')],
            ]],
            'block' => ['type' => 'object', 'required' => ['component'], 'properties' => [
                'component' => ['enum' => ComponentCatalog::BLOCKS], 'id' => $str, 'title' => $str,
                'span' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 24], 'refresh' => ['type' => 'integer', 'minimum' => 5],
            ], 'allOf' => [
                $when('component', 'chart', $ref('chart')),
                $when('component', 'stat', $req('api', 'field')),
                $when('component', 'rank', $req('api', 'label', 'value')),
                $when('component', 'shortcuts', ['required' => ['items'], 'properties' => ['items' => $listOf('shortcut')]]),
                $when('component', 'descriptions', ['required' => ['items', 'columns'], 'properties' => ['items' => $listOf('column'), 'columns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4]]]),
                $when('component', 'table', ['required' => ['page'], 'properties' => ['page' => $code, 'params' => $params]]),
                $when('component', 'timeline', $req('api')),
                $when('component', 'markdown', $req('content')),
                $when('component', 'alert', $req('type', 'message')),
                $when('component', ['card', 'grid'], ['required' => ['blocks'], 'properties' => ['blocks' => $listOf('block')]]),
                $when('component', 'collapse', ['required' => ['panels'], 'properties' => ['panels' => $listOf('tab')]]),
            ]],
            'tab' => ['type' => 'object', 'required' => ['title', 'blocks'], 'properties' => ['title' => $str, 'blocks' => $listOf('block')], 'additionalProperties' => false],
            'section' => ['type' => 'object', 'required' => ['fields'], 'properties' => ['title' => $str, 'collapsible' => $bool, 'fields' => $listOf('field')], 'additionalProperties' => false],
            'submit' => ['type' => 'object', 'required' => ['method', 'api'], 'properties' => ['method' => ['enum' => ['POST', 'PUT', 'PATCH']], 'api' => $api], 'additionalProperties' => false],
            'tableBody' => ['type' => 'object', 'required' => ['api', 'rowKey', 'columns'], 'additionalProperties' => false, 'properties' => [
                'api' => $api, 'rowKey' => $str, 'search' => $listOf('field'), 'columns' => $listOf('column'), 'toolbar' => $listOf('action'), 'rowActions' => $listOf('action'),
                'selection' => $bool, 'pageSizes' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                'defaultSort' => ['type' => 'object', 'required' => ['field', 'order'], 'properties' => ['field' => $str, 'order' => ['enum' => ['asc', 'desc']]], 'additionalProperties' => false],
                'pagination' => ['const' => false],
                'tree' => ['type' => 'object', 'required' => ['children', 'expanded'], 'additionalProperties' => false,
                    'properties' => ['children' => $str, 'column' => $str, 'expanded' => $bool]],
                'notice' => ['type' => 'object', 'required' => ['message', 'type'], 'additionalProperties' => false,
                    'properties' => ['message' => $str, 'api' => $api, 'type' => ['enum' => ['info', 'success', 'warning', 'error']]]],
            ]],
            'formBody' => ['type' => 'object', 'required' => ['submit', 'sections', 'actions'], 'additionalProperties' => false, 'properties' => [
                'load' => $api,
                'submit' => ['anyOf' => [$ref('submit'), ['type' => 'object', 'properties' => ['create' => $ref('submit'), 'update' => $ref('submit')], 'additionalProperties' => false, 'minProperties' => 1]]],
                'layout' => ['enum' => ['vertical', 'horizontal', 'inline']], 'columns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
                'sections' => $listOf('section'), 'actions' => $listOf('action'),
            ]],
            'dashboardBody' => ['type' => 'object', 'required' => ['grid', 'blocks'], 'additionalProperties' => false, 'properties' => [
                'grid' => ['const' => 24], 'blocks' => $listOf('block'),
            ]],
            'detailBody' => ['type' => 'object', 'required' => ['load'], 'additionalProperties' => false, 'properties' => [
                'load' => $api,
                'header' => ['type' => 'object', 'required' => ['title'], 'properties' => ['title' => $str, 'tags' => $listOf('column'), 'actions' => $listOf('action')], 'additionalProperties' => false],
                'tabs' => $listOf('tab'), 'blocks' => $listOf('block'),
            ]],
            'customBody' => ['type' => 'object', 'required' => ['component', 'config'], 'additionalProperties' => false, 'properties' => [
                'component' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]*$'], 'config' => ['type' => 'object'],
            ]],
        ];

        $root = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => self::ID,
            'title' => 'loongs/render page description',
            'type' => 'object',
            'required' => ['schema', 'page', 'type', 'title', 'version', 'breadcrumb', 'body', 'meta'],
            'additionalProperties' => false,
            'properties' => [
                'schema' => ['const' => self::VERSION],
                'page' => $code,
                'type' => ['enum' => array_map(static fn (PageType $t): string => $t->value, PageType::cases())],
                'title' => $str,
                'version' => ['type' => 'string', 'pattern' => '^[0-9a-f]{12}$'],
                'breadcrumb' => ['type' => 'array', 'items' => $str],
                'body' => ['type' => 'object'],
                'meta' => ['type' => 'object', 'required' => ['platforms', 'platform'], 'additionalProperties' => false, 'properties' => [
                    'platforms' => ['type' => 'array', 'minItems' => 1, 'items' => ['enum' => ['pc', 'mobile']]],
                    'platform' => ['enum' => ['pc', 'mobile']],
                    'env' => ['enum' => array_map(static fn (ClientEnv $e): string => $e->value, ClientEnv::cases())],
                ]],
            ],
            'allOf' => array_map(static fn (PageType $t): array => $when('type', $t->value, ['properties' => ['body' => $ref($t->value . 'Body')]]), PageType::cases()),
            '$defs' => $defs,
        ];

        return $root;
    }

    public static function json(int $flags = JSON_PRETTY_PRINT): string
    {
        return json_encode(self::jsonSchema(), $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
