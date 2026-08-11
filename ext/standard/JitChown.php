<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ChownRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for chown()/lchown() via __compiler_chown (php-in-PHP ChownRuntime; #30167). */
final class JitChown
{
    /** @return Value true when __compiler_chown returns 1 */
    public static function invoke(Context $context, Value $pathStr, Value $userVal, bool $lchown): Value
    {
        ChownRuntime::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $flag = $i32->constInt($lchown ? 1 : 0, false);
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_chown'),
            $pathStr,
            $userVal,
            $flag
        );
        $one = $i32->constInt(1, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }
}
