<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * settype() in-place cast for compiled JIT/AOT modules (#17335, php-in-PHP).
 *
 * SSOT: {@see VmSettype}
 * php-src: ext/standard/type.c — php_settype / convert_to_*
 */
final class SettypeJitHelper
{
    public static function applyInPlace(Variable $slot, string $typeName): void
    {
        VmSettype::apply($slot, $typeName, null);
    }
}
