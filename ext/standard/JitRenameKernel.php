<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_rename_kernel() — thin libc rename(2) (#19215, #20028, #20603).
 *
 * Nested leaf inside RenameJitHelper only (user-script AOT always goes through
 * {@see RenameJitHelper} via {@see \PHPCompiler\JIT\Builtin\StringRename}).
 */
final class JitRenameKernel
{
    /** @return Value i1 — true when rename(2) returns 0 */
    public static function invoke(Context $context, Value $fromStr, Value $toStr): Value
    {
        \PHPCompiler\JIT\LibcExtern::register($context);
        $map = $context->structFieldMap['__string__'];
        $fromPtr = $context->builder->structGep($fromStr, $map['value']);
        $toPtr = $context->builder->structGep($toStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('rename'),
            $fromPtr,
            $toPtr
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }
}
