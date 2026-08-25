<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\fileinfo\finfo_set_flags;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * finfo::set_flags() — thin AOT bool true (#34688, re-#3366).
 *
 * php-src: ext/fileinfo/fileinfo.c — zim_finfo_set_flags
 */
final class FinfoSetFlags implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return finfo_set_flags::lowerSetFlags($context, true, ...$args);
    }
}
