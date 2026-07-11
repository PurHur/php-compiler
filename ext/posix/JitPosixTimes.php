<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Builtin\StringPosixTimes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/**
 * LLVM lowering for posix_times() via PosixTimesJitHelper (#9218).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_times)
 */
final class JitPosixTimes
{
    public static function invoke(Context $context): Value
    {
        StringPosixTimes::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_posix_times'),
            $ptr
        );

        return $ptr;
    }
}
