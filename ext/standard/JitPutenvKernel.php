<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_putenv_kernel() — thin libc setenv mirror (#23414).
 *
 * Nested leaf inside {@see PutenvJitHelper}. Takes the full "NAME=value" assignment
 * (same proven path as former JitEnv::emitLibcPutenvMirror — #5965 REQUEST_BODY).
 * Uses POSIX setenv() (copies name/value); length+NUL copy because __string__ constants
 * may lack a trailing NUL.
 * php-src: ext/standard/basic_functions.c — zif_putenv
 */
final class JitPutenvKernel
{
    /**
     * Mirror "NAME=value" into process environ; no-op when assignment lacks '='.
     * Unset ("NAME" without '=') uses unsetenv.
     */
    public static function invoke(Context $context, Value $assignmentStr): void
    {
        LibcExtern::register($context);
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);

        $len = $context->builder->load(
            $context->builder->structGep($assignmentStr, $map['length'])
        );
        $bytes = $context->builder->structGep($assignmentStr, $map['value']);
        $lenSize = $len->typeOf() === $sizeT
            ? $len
            : $context->builder->truncOrBitCast($len, $sizeT);
        $bufLen = $context->builder->add($lenSize, $one);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $bufLen);
        $cStr = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy($cStr, $bytes, $len, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($cStr, $len)
        );

        $eq = $context->builder->call(
            $context->lookupFunction('strchr'),
            $cStr,
            $i32->constInt(ord('='), false)
        );
        $hasEq = $context->builder->icmp(Builder::INT_NE, $eq, $i8p->constNull());
        $setBb = BasicBlockHelper::append($context, 'putenv_kernel_setenv');
        $unsetBb = BasicBlockHelper::append($context, 'putenv_kernel_unsetenv');
        $doneBb = BasicBlockHelper::append($context, 'putenv_kernel_done');
        $context->builder->branchIf($hasEq, $setBb, $unsetBb);

        $context->builder->positionAtEnd($setBb);
        $context->builder->store($i8->constInt(0, false), $eq);
        $valueStart = $context->builder->inBoundsGEP($eq, $one);
        $context->builder->call(
            $context->lookupFunction('setenv'),
            $cStr,
            $valueStart,
            $i32->constInt(1, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($unsetBb);
        $context->builder->call(
            $context->lookupFunction('unsetenv'),
            $cStr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->call($context->lookupFunction('free'), $cStr);
    }
}
