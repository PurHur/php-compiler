<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStrtotime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for strtotime() via StringStrtotime (__compiler_strtotime, #10742).
 *
 * Thin user-script AOT: NestedJIT of VmDateTimeNative is unsafe (trim/=== lower wrong —
 * #27091). Compile-time datetime literals fold via {@see VmDateTimeNative::strtotime()}
 * (php-src-strict SSOT). Dynamic args keep the i64 hasBase ABI bridge.
 */
final class JitStrtotime
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        // Arity enforced by strtotime::call (#30714).
        $argc = \count($args);

        $folded = self::tryFoldCompileTime($context, $argc, $args);
        if (null !== $folded) {
            return $folded;
        }

        StringStrtotime::ensureLinked($context);

        // Soft-null on 8.4 — Zend deprecate+coerce (#21208, reverts #19651 TypeError)
        $time = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strtotime', 0, 'datetime')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strtotime', 0, 'datetime');
        // ABI: __compiler_strtotime(str*, i64 hasBase, i64 base, value*) — not i1 (#27091)
        $i64 = $context->getTypeFromString('int64');
        $hasBaseFlag = 2 === $argc && !self::isNullJitArg($args[1]);
        $hasBase = $i64->constInt($hasBaseFlag ? 1 : 0, false);
        $base = $hasBaseFlag
            ? self::jitOptionalIntArg($context, $args[1], 2)
            : $i64->constInt(0, false);

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

    /**
     * @param JITVariable ...$args
     */
    private static function tryFoldCompileTime(Context $context, int $argc, array $args): ?Value
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null === $lit) {
            return null;
        }
        $hasBase = 2 === $argc && !self::isNullJitArg($args[1]);
        $base = null;
        if ($hasBase) {
            $base = self::compileTimeIntArg($args[1]);
            if (null === $base) {
                return null;
            }
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'strtotime_fold');
        $result = $hasBase
            ? VmDateTimeNative::strtotime($lit, $base)
            : VmDateTimeNative::strtotime($lit);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $result) {
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        } else {
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt($result, true)
            );
        }

        return $ptr;
    }

    private static function compileTimeIntArg(JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }

        return null;
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
