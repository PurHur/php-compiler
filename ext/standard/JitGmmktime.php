<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringGmmktime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for gmmktime() via StringGmmktime (__compiler_gmmktime, #7001). */
final class JitGmmktime
{
    public static function invoke(
        Context $context,
        JITVariable $hour,
        ?JITVariable $minute,
        ?JITVariable $second,
        ?JITVariable $month,
        ?JITVariable $day,
        ?JITVariable $year,
        int $argc
    ): Value {
        StringGmmktime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_gmmktime'),
            self::jitIntArg($context, $hour, 1),
            self::jitOptionalIntArg($context, $minute, 2, $argc >= 2),
            self::jitOptionalIntArg($context, $second, 3, $argc >= 3),
            self::jitOptionalIntArg($context, $month, 4, $argc >= 4),
            self::jitOptionalIntArg($context, $day, 5, $argc >= 5),
            self::jitOptionalIntArg($context, $year, 6, $argc >= 6),
            $context->constantFromBool($argc < 2 || self::isNullJitArg($minute)),
            $ptr
        );

        return $ptr;
    }

    private static function jitIntArg(Context $context, JITVariable $arg, int $position): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                throw new \LogicException('gmmktime() argument #'.$position.' must be an integer in this compiler build');
            }

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        throw new \LogicException('gmmktime() argument #'.$position.' must be an integer in this compiler build');
    }

    private static function jitOptionalIntArg(
        Context $context,
        ?JITVariable $arg,
        int $position,
        bool $passed
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        if (!$passed || null === $arg || self::isNullJitArg($arg)) {
            return $i64->constInt(MktimeJitHelper::ARG_NULL, false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        throw new \LogicException('gmmktime() argument #'.$position.' must be an integer or null in this compiler build');
    }

    private static function isNullJitArg(?JITVariable $arg): bool
    {
        return null === $arg || JITVariable::TYPE_NULL === $arg->type;
    }
}
