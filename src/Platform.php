<?php

declare(strict_types=1);

namespace Loongs\Render;

/** X-Client-Platform: pc (Flutter) | mobile (uni-app H5 / App / mini program). Missing → pc. */
enum Platform: string
{
    case Pc = 'pc';
    case Mobile = 'mobile';

    public static function fromHeader(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Pc;
    }
}
