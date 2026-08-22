<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM libc unlink(2) body for __phpc_jit_unlink (#33412).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\UnlinkJitHelper} cannot delete files under thin
 * AOT: host \\unlink() re-enters __phpc_jit_unlink (peer touch #28995 / mkdir #33402 / chown #32466).
 * Platform unlink(2) is the justified thin ABI (php-src VCWD_UNLINK / php_unlink).
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(unlink) / php_unlink
 */
final class UnlinkLibcRuntime
{
    public static function emit(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('unlink_libc_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i32->constInt(0, false);
        $true = $i1->constInt(1, false);
        $false = $i1->constInt(0, false);

        $path = $fn->getParam(0);
        $fail = $fn->appendBasicBlock('unlink_libc_fail');
        $call = $fn->appendBasicBlock('unlink_libc_call');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNull, $fail, $call);

        $context->builder->positionAtEnd($call);
        $p = self::stringData($context, $path);
        $rc = $context->builder->call($context->lookupFunction('unlink'), $p);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
        $context->builder->returnValue($context->builder->select($ok, $true, $false));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($false);
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        // getNamedFunction before addFunction — lookup miss must not mint unlink.1 (#33774 / #32122).
        LibcExtern::ensureExternalDecl(
            $context,
            'unlink',
            $context->context->functionType($i32, false, $i8p)
        );
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }
}
