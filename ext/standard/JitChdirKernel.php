<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_chdir_kernel() — thin libc chdir(2) (#21147).
 *
 * Nested leaf inside ChdirJitHelper only (user-script AOT always goes through
 * {@see ChdirJitHelper} via {@see \PHPCompiler\JIT\Builtin\StringChdir}).
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chdir)
 */
final class JitChdirKernel
{
    /** @return Value i1 — true when chdir(2) returns 0 */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        \PHPCompiler\JIT\LibcExtern::register($context);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('chdir'),
            $pathPtr
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }
}
