<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringStrspn;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** JIT lowering for strspn()/strcspn() via StringStrspn PHP bridge (#14700). */
final class SpnJitLowering
{
    /**
     * @param list<JITVariable> $args
     */
    public static function extended(Context $context, array $args, bool $isStrspn, string $name): Value
    {
        $argc = \count($args);
        StringStrspn::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strVal = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], $name, 0, 'string');
        $maskVal = JitStringBuiltinArg::lowerZparamStr($context, $args[1], $name, 1, 'characters');
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
}
