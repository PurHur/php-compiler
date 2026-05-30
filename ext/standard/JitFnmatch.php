<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fnmatch() via libc fnmatch(3) in phpc_fs_dir.c (issue #3189). */
final class JitFnmatch
{
    public static function invoke(Context $context, Value $pattern, Value $string, Value $flagsI32): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $result = $context->builder->call(
            $context->lookupFunction('__phpc_fnmatch'),
            $pattern,
            $string,
            $flagsI32
        );

        return $context->builder->icmp(
            Builder::INT_NE,
            $result,
            $i32->constInt(0, false)
        );
    }
}
