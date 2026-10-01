<?php

declare(strict_types=1);

namespace Loongs\Render;

/** X-Client-Env of the uni-app client (only meaningful for Platform::Mobile). */
enum ClientEnv: string
{
    case H5 = 'h5';
    case App = 'app';
    case MpWeixin = 'mp-weixin';

    public static function fromHeader(?string $value): ?self
    {
        return self::tryFrom(strtolower(trim((string) $value)));
    }
}
