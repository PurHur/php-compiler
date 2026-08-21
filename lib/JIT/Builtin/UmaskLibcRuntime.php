<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM libc umask(2) bodies for phpc_umask_get / phpc_umask_set (#33422).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\UmaskJitHelper} cannot call host \\umask()
 * under thin AOT — that re-enters these ABIs (peer unlink #33412 / rmdir #33403).
 * Platform umask(2) is the justified thin ABI (php-src VCWD_UMASK / PHP_FUNCTION(umask)).
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(umask)
 */
final class UmaskLibcRuntime
{
    public static function emitGet(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('umask_libc_get_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i32->constInt(0, false);
        // Read current mask without permanently changing it: umask(0); umask(old); return old.
        $old = $context->builder->call($context->lookupFunction('umask'), $zero);
        $context->builder->call($context->lookupFunction('umask'), $old);
        $context->builder->returnValue($context->builder->zExt($old, $i64));
    }

    public static function emitSet(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('umask_libc_set_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $maskI64 = $fn->getParam(0);
        $mask = $context->builder->trunc($maskI64, $i32);
        $prev = $context->builder->call($context->lookupFunction('umask'), $mask);
        $context->builder->returnValue($context->builder->zExt($prev, $i64));
    }

    private static function ensureLibc(Context $context): void
    {
        LibcExtern::ensureUmask($context);
    }
}
