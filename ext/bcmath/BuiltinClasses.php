<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\VM\Context;

/**
 * Register bcmath builtin classes (php-src ext/bcmath/bcmath.c; issue #7220).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmBcMathNumber::registerClass($ctx);
    }
}
