<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for sys_get_temp_dir() via __compiler_sys_get_temp_dir. */
final class JitSysGetTempDir
{
    public static function invoke(Context $context): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_sys_get_temp_dir')
        );
    }
}
