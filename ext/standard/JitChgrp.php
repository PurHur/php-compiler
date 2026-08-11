<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ChownRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for chgrp()/lchgrp() via __compiler_chgrp (php-in-PHP ChownRuntime; #30167). */
final class JitChgrp
{
    /** @return Value true when __compiler_chgrp returns 1 */
    public static function invoke(Context $context, Value $pathStr, Value $groupVal, bool $lchgrp): Value
    {
        ChownRuntime::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $flag = $i32->constInt($lchgrp ? 1 : 0, false);
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_chgrp'),
            $pathStr,
            $groupVal,
            $flag
        );
        $one = $i32->constInt(1, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }
}
