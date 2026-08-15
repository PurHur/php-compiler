<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringStrspn;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT lowering for strspn()/strcspn() via length-bounded LLVM (#14700, #27053, #27054).
 *
 * Compile-time literals fold through {@see VmString}; runtime uses {@see StringStrspn}.
 * $characters soft-null DEP+coerce (#29394) — same path as $string (#21195).
 */
final class SpnJitLowering
{
    /**
     * @param list<JITVariable> $args
     */
    public static function extended(Context $context, array $args, bool $isStrspn, string $name): Value
    {
        $argc = \count($args);
        $folded = self::tryCompileTimeFold($context, $args, $isStrspn);
        if (null !== $folded) {
            return $folded;
        }

        StringStrspn::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strVal = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], $name, 0, 'string');
        // $characters soft-null like Zend (#29394) — not lowerZparamStr (8.4 null TypeError).
        $maskVal = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], $name, 1, 'characters');
        $offset = $argc >= 3
            ? JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], $name, 3, 'offset')
            : $i64->constInt(0, false);
        $length = 4 === $argc
            ? JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[3], $name, 4, 'length')
            : $i64->constInt(0, false);
        $lenIsNull = $i32->constInt(4 === $argc ? 0 : 1, false);
        $mode = $i32->constInt($isStrspn ? 1 : 0, false);

        return $context->builder->call(
            $context->lookupFunction('phpc_strspn_extended'),
            $strVal,
            $maskVal,
            $offset,
            $length,
            $lenIsNull,
            $mode
        );
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeFold(Context $context, array $args, bool $isStrspn): ?Value
    {
        $str = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        $mask = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $str || null === $mask) {
            return null;
        }
        $argc = \count($args);
        $offset = 0;
        if ($argc >= 3) {
            $offset = $args[2]->compileTimeLong;
            if (null === $offset) {
                return null;
            }
        }
        $length = null;
        if (4 === $argc) {
            $length = $args[3]->compileTimeLong;
            if (null === $length) {
                return null;
            }
        }
        $n = $isStrspn
            ? VmString::strspn($str, $mask, (int) $offset, $length)
            : VmString::strcspn($str, $mask, (int) $offset, $length);

        return $context->getTypeFromString('int64')->constInt($n, true);
    }
}
