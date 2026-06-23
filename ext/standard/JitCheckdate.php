<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\CheckdateRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
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
        CheckdateRuntime::ensureLinked($context);

        $month = JitIntdiv::lowerIntBuiltinArg($context, $args[0], 'checkdate', 1, 'month');
        $day = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'checkdate', 2, 'day');
        $year = JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'checkdate', 3, 'year');

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
