<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringMktime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for mktime() via StringMktime (__compiler_mktime, #3292). */
final class JitMktime
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
        StringMktime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_mktime'),
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
            // Z_PARAM_LONG $hour — null TypeError on PROFILE=8.4 (#20227).
            if ($context->callerStrictTypes || VmMath::requiresForwardProfileStrictLongNull()) {
                self::emitIntTypeErrorAndAbort($context, $position, 'null');

                return $context->getTypeFromString('int64')->constInt(0, false);
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

        throw new \LogicException('mktime() argument #'.$position.' must be an integer in this compiler build');
    }

    private static function emitIntTypeErrorAndAbort(Context $context, int $position, string $given): void
    {
        $name = match ($position) {
            1 => 'hour',
            2 => 'minute',
            3 => 'second',
            4 => 'month',
            5 => 'day',
            6 => 'year',
            default => 'arg',
        };
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            \sprintf(
                'mktime(): Argument #%d ($%s) must be of type int, %s given',
                $position,
                $name,
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function jitOptionalIntArg(
        Context $context,
        ?JITVariable $arg,
        int $position,
        bool $passed
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        if (!$passed || null === $arg || self::isNullJitArg($arg)) {
            return $i64->constInt(\PHPCompiler\ext\standard\MktimeJitHelper::ARG_NULL, false);
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

        throw new \LogicException('mktime() argument #'.$position.' must be an integer or null in this compiler build');
    }

    private static function isNullJitArg(?JITVariable $arg): bool
    {
        return null === $arg || JITVariable::TYPE_NULL === $arg->type;
    }
}
