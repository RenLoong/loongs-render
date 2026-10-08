<?php

declare(strict_types=1);

namespace Loongs\Render;

/**
 * What the requesting client is and what it can render (RENDER.md §2.1):
 *   X-Client-Platform: pc | mobile      (missing / unknown → pc)
 *   X-Client-Env:      h5 | app | mp-weixin   (mobile only; ignored for pc)
 *   X-Render-Schema:   highest description schema the client understands (missing → Schema::VERSION)
 *   X-Render-Components: optional hash of the client's registered components (ComponentCatalog::hash())
 *
 * $locale is the language the description is rendered in (set by the host, e.g. negotiated from
 * Accept-Language with loongs/language: ->withLocale($tag)); null = untranslated source texts. It is
 * part of cacheKey(), so cached descriptions / ETags differ per language.
 */
final class ClientInfo
{
    public bool $isMobile {
        get => $this->platform === Platform::Mobile;
    }

    public function __construct(
        public private(set) Platform $platform = Platform::Pc,
        public private(set) ?ClientEnv $env = null,
        public private(set) int $schema = Schema::VERSION,
        public private(set) ?string $componentsHash = null,
        public private(set) ?string $locale = null,
    ) {
        if ($locale !== null && preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8}){0,4}$/', $locale) !== 1) {
            $this->locale = null;
        }
        if ($platform === Platform::Pc) {
            $this->env = null;
        }
    }

    public static function pc(): self
    {
        return new self();
    }

    public static function mobile(ClientEnv $env = ClientEnv::H5): self
    {
        return new self(Platform::Mobile, $env);
    }

    /** @param array<string, string|list<string>> $headers any case; list values (PSR-7 style) use the first item */
    public static function fromHeaders(array $headers): self
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string) $k)] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        }
        $schema = trim($h['x-render-schema'] ?? '');
        $hash = trim($h['x-render-components'] ?? '');

        return new self(
            Platform::fromHeader($h['x-client-platform'] ?? null),
            ClientEnv::fromHeader($h['x-client-env'] ?? null),
            ctype_digit($schema) ? (int) $schema : Schema::VERSION,
            preg_match('/^[A-Za-z0-9_-]{1,128}$/', $hash) === 1 ? $hash : null,
        );
    }

    /** Same client, rendered in $locale (BCP 47 tag such as "en-US"; null = source texts). */
    public function withLocale(?string $locale): self
    {
        return new self($this->platform, $this->env, $this->schema, $this->componentsHash, $locale);
    }

    /** Stable key part for caches: "pc" | "mobile" | "mobile.mp-weixin", plus "@en-US" when localized. */
    public function cacheKey(): string
    {
        return $this->platform->value . ($this->env !== null ? '.' . $this->env->value : '') . ($this->locale !== null ? '@' . $this->locale : '');
    }
}
