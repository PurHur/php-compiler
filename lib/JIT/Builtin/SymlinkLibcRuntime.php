<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM libc symlink(2) body for __phpc_jit_symlink (#33415).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\SymlinkJitHelper} cannot create links under
 * thin AOT: host \\symlink() re-enters __phpc_jit_symlink, and FFI is unavailable in the
 * native binary. Platform symlink(2) is the justified thin ABI (peer {@see RmdirLibcRuntime}
 * #33403 / {@see MkdirLibcRuntime} #33402 / link NestedJIT leaf #33406).
 *
 * php-src: ext/standard/link.c — php_symlink / VCWD_SYMLINK
 */
final class SymlinkLibcRuntime
{
    public static function emit(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('symlink_libc_entry');
        $context->builder->positionAtEnd($entry);

        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $target = $fn->getParam(0);
        $link = $fn->getParam(1);
        $false = $i1->constInt(0, false);
        $true = $i1->constInt(1, false);
        $zero = $i32->constInt(0, false);

        $fail = $fn->appendBasicBlock('symlink_libc_fail');
        $checkLink = $fn->appendBasicBlock('symlink_libc_check_link');
        $body = $fn->appendBasicBlock('symlink_libc_body');

        $targetNull = $context->builder->icmp(Builder::INT_EQ, $target, $strPtr->constNull());
        $context->builder->branchIf($targetNull, $fail, $checkLink);

        $context->builder->positionAtEnd($checkLink);
        $linkNull = $context->builder->icmp(Builder::INT_EQ, $link, $strPtr->constNull());
        $context->builder->branchIf($linkNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $targetPtr = self::stringData($context, $target);
        $linkPtr = self::stringData($context, $link);
        $rc = $context->builder->call(
            $context->lookupFunction('symlink'),
            $targetPtr,
            $linkPtr
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
        $context->builder->returnValue($context->builder->select($ok, $true, $false));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($false);
    }

    private static function ensureLibc(Context $context): void
    {
        LibcExtern::ensureSymlink($context);
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }
}
