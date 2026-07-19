<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringGethostname;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for gethostname() via GethostnameJitHelper PHP (#21166).
 *
 * Empty hostname string boxes to false (php-src / getcwd #10451 shape).
 */
final class JitGethostname
{
    /** @return Value `__value__*` — string hostname or bool false */
    public static function invoke(Context $context): Value
    {
        $resolved = StringGethostname::invoke($context);

        return JitGetcwd::boxed($context, $resolved);
    }
}
