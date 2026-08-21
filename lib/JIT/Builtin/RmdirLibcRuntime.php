<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM libc rmdir(2) body for __phpc_jit_rmdir (#33403).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\RmdirJitHelper} cannot remove dirs under
 * thin AOT: host \\rmdir() re-enters __phpc_jit_rmdir, and FFI is unavailable in the
 * native binary. Platform rmdir(2) is the justified thin ABI (peer {@see ChownLibcRuntime}
 * #32466 / {@see TouchLibcRuntime} #28995).
 *
 * php-src: ext/standard/filestat.c — php_rmdir / VCWD_RMDIR
 */
final class RmdirLibcRuntime
{
    public static function emit(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('rmdir_libc_entry');
        $context->builder->positionAtEnd($entry);

        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $path = $fn->getParam(0);
        $false = $i1->constInt(0, false);
        $true = $i1->constInt(1, false);
        $zero = $i32->constInt(0, false);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $fail = $fn->appendBasicBlock('rmdir_libc_fail');
        $body = $fn->appendBasicBlock('rmdir_libc_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $p = self::stringData($context, $path);
        $rc = $context->builder->call($context->lookupFunction('rmdir'), $p);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
        $context->builder->returnValue($context->builder->select($ok, $true, $false));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($false);
    }

    private static function ensureLibc(Context $context): void
    {
        LibcExtern::ensureRmdir($context);
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }
}
