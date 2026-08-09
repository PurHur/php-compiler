<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\SysGetTempDirRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for sys_get_temp_dir() via SysGetTempDirJitHelper PHP (#29433). */
final class JitSysGetTempDir
{
    public static function invoke(Context $context): Value
    {
        return SysGetTempDirRuntime::invoke($context);
    }
}
