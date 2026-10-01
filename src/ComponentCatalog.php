<?php

declare(strict_types=1);

namespace Loongs\Render;

/**
 * Component names of description schema 1 (RENDER.md §7) and what each client platform / env
 * supports. Unsupported nodes are removed while rendering for that client. Clients may send
 * X-Render-Components = hash() of the catalog they were built against.
 */
final class ComponentCatalog
{
    public const FIELDS = ['text', 'textarea', 'password', 'number', 'money', 'select', 'radio', 'checkbox', 'switch', 'date', 'datetime',
        'dateRange', 'time', 'treeSelect', 'cascader', 'upload', 'image', 'richText', 'json'];
    public const COLUMNS = ['text', 'tag', 'badge', 'datetime', 'money', 'image', 'link', 'copyable', 'progress', 'bool'];
    public const BLOCKS = ['stat', 'chart', 'rank', 'shortcuts', 'descriptions', 'table', 'timeline', 'markdown', 'alert', 'card', 'collapse', 'grid'];
    public const LAYOUTS = ['sections', 'tabs', 'grid', 'card', 'collapse'];
    public const ACTIONS = ['openForm', 'openDetail', 'navigate', 'request', 'batchRequest', 'export', 'import', 'refresh', 'submit', 'close'];
    public const CHARTS = ['line', 'bar', 'stackedBar', 'area', 'pie', 'donut', 'radar', 'scatter', 'gauge', 'combo', 'custom'];

    /**
     * @param array<string, list<string>> $unsupported  "<platform>[.<env>]" => list of "kind:component"
     *        (kind = field | column | block | action | chart), e.g. ['mobile' => ['field:richText']]
     */
    public function __construct(
        private array $unsupported = [
            // first uni-app batch (RENDER.md §8.2): read-only lists, no multi-select / rich text editor
            'mobile' => ['field:richText', 'action:batchRequest', 'action:import'],
        ],
    ) {
    }

    public function supports(ClientInfo $client, string $kind, string $component): bool
    {
        $id = "{$kind}:{$component}";
        foreach ([$client->platform->value, $client->cacheKey()] as $key) {
            if (in_array($id, $this->unsupported[$key] ?? [], true)) {
                return false;
            }
        }

        return true;
    }

    public function withUnsupported(string $platformKey, string ...$ids): self
    {
        $u = $this->unsupported;
        $u[$platformKey] = array_values(array_unique([...$u[$platformKey] ?? [], ...$ids]));

        return new self($u);
    }

    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return ['field' => self::FIELDS, 'column' => self::COLUMNS, 'block' => self::BLOCKS, 'layout' => self::LAYOUTS,
            'action' => self::ACTIONS, 'chart' => self::CHARTS];
    }

    /** Hash of schema version + component names (what X-Render-Components is compared with). */
    public static function hash(): string
    {
        return substr(hash('sha256', Schema::VERSION . json_encode(self::all())), 0, 16);
    }
}
