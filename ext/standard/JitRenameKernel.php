<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_rename_kernel() — thin libc rename(2) (#19215).
 *
 * Used inside RenameJitHelper / VmFsPathPure so nested helper units do not
 * recurse through the rename() builtin bridge.
 */
final class JitRenameKernel
{
    /** @return Value i1 — true when rename(2) returns 0 */
    public static function invoke(Context $context, Value $fromStr, Value $toStr): Value
    {
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
