<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringSysGetloadavg;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for sys_getloadavg() via SysGetloadavgJitHelper (#12106).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sys_getloadavg)
 */
final class JitSysGetloadavg
{
    public static function invoke(Context $context): Value
    {
        StringSysGetloadavg::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_sys_getloadavg'),
            $ptr
        );

        return $ptr;
    }
}
