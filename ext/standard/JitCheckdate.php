<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\CheckdateRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for checkdate() via CheckdateRuntime (#3292). */
final class JitCheckdate
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('checkdate() expects exactly 3 arguments in this compiler build');
        }
        JitInternalStrictArg::rejectNullInt($context, $args[0], 'checkdate', 'month', 1);
        JitInternalStrictArg::rejectNullInt($context, $args[1], 'checkdate', 'day', 2);
        JitInternalStrictArg::rejectNullInt($context, $args[2], 'checkdate', 'year', 3);
        JitInternalStrictArg::requireInt($context, $args[0], 'checkdate', 'month', 1);
        JitInternalStrictArg::requireInt($context, $args[1], 'checkdate', 'day', 2);
        JitInternalStrictArg::requireInt($context, $args[2], 'checkdate', 'year', 3);
        CheckdateRuntime::ensureLinked($context);

        $month = JitLongArg::lower($context, $args[0], 'checkdate() argument #1');
        $day = JitLongArg::lower($context, $args[1], 'checkdate() argument #2');
        $year = JitLongArg::lower($context, $args[2], 'checkdate() argument #3');

        $valid = $context->builder->call(
            $context->lookupFunction('__compiler_checkdate'),
            $month,
            $day,
            $year
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $valid);

        return $ptr;
    }
}
