<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringStrptime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for strptime() via StringStrptime (__compiler_strptime, #3694). */
final class JitStrptime
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'strptime() expects exactly 2 arguments, %d given',
                \count($args)
            ));
        }
        // php-src: ext/date/php_date.c — PHP_FUNCTION(strptime) deprecated since 8.1 (#22771).
        VmEngineBuiltinDeprecation::emitJitFunction($context, 'strptime');

        StringStrptime::ensureLinked($context);

        $date = JitStringBuiltinArg::lower($context, $args[0], 'strptime', 1, 'date');
        $format = JitStringBuiltinArg::lower($context, $args[1], 'strptime', 2, 'format');

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_strptime'),
            $date,
            $format,
            $ptr
        );

        return $ptr;
    }
}
