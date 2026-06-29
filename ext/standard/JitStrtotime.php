<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringStrtotime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for strtotime() via StringStrtotime (__compiler_strtotime, #10742). */
final class JitStrtotime
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('strtotime() expects 1 or 2 arguments in this compiler build');
        }

        StringStrtotime::ensureLinked($context);

        $time = JitStringBuiltinArg::lower($context, $args[0], 'strtotime', 0, 'datetime');
        $hasBase = $context->constantFromBool(2 === $argc && !self::isNullJitArg($args[1]));
        $base = (2 === $argc && !self::isNullJitArg($args[1]))
            ? self::jitOptionalIntArg($context, $args[1], 2)
            : $context->getTypeFromString('int64')->constInt(0, false);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_strtotime'),
            $time,
            $hasBase,
            $base,
            $ptr
        );

        return $ptr;
    }

    private static function jitOptionalIntArg(Context $context, JITVariable $arg, int $position): Value
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

        throw new \LogicException('strtotime() argument #'.$position.' must be an integer or null in this compiler build');
    }

    private static function isNullJitArg(?JITVariable $arg): bool
    {
        return null === $arg || JITVariable::TYPE_NULL === $arg->type;
    }
}
