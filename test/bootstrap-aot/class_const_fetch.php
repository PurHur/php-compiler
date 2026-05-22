<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: class constant fetch (issue #84 / Expr_ClassConstFetch).
 */

namespace BootstrapAot;

class Mode
{
    public const NORMAL = 1;
    public const AOT = 2;

    public function label(): string
    {
        return $this->pick() === self::NORMAL ? 'normal' : 'aot';
    }

    private function pick(): int
    {
        return self::NORMAL;
    }
}

echo "ok\n";
