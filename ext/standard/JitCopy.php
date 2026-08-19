<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\CopyRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for copy() via __compiler_copy (php-in-PHP CopyRuntime; #32466). */
final class JitCopy
{
    /** @return Value
     * true when __compiler_copy returns 1 */
    public static function invoke(Context $context, Value $fromStr, Value $toStr): Value
    {
        CopyRuntime::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_copy'),
            $fromStr,
            $toStr
        );
        $one = $i32->constInt(1, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }
}
