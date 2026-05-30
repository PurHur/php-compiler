<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringGetrusage;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for getrusage() via __compiler_getrusage (JIT/AOT, issue #3240). */
final class JitGetrusage
{
    public static function invoke(Context $context, ?JITVariable $who = null): Value
    {
        StringGetrusage::ensureLinked($context);

        $whoVal = null === $who
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : self::jitWhoArg($context, $who);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_getrusage'),
            $whoVal,
            $ptr
        );

        return $ptr;
    }

    private static function jitWhoArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        throw new \LogicException('getrusage() who must be an integer in this compiler build');
    }
}
