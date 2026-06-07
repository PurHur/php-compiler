<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for posix v1 builtins (#7271). */
final class JitPosix
{
    private static int $blockSerial = 0;

    public static function getpid(Context $context): Value
    {
        self::ensureLibcPid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getpid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    public static function getppid(Context $context): Value
    {
        self::ensureLibcPid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getppid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    public static function strerror(Context $context, JITVariable $errnoArg): Value
    {
        self::ensureLibcStrerror($context);
        $errno = JitLongArg::lower($context, $errnoArg, 'posix_strerror() errno');
        $i32 = $context->getTypeFromString('int32');
        $zeroI32 = $i32->constInt(0, false);
        $errnoI32 = $errno->typeOf() === $i32
            ? $errno
            : $context->builder->trunc($errno, $i32);

        $id = (string) (++self::$blockSerial);
        $negBlock = BasicBlockHelper::append($context, 'posix_strerror_neg_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'posix_strerror_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'posix_strerror_done_'.$id);

        $isNeg = $context->builder->icmp(Builder::INT_SLT, $errnoI32, $zeroI32);
        $context->builder->branchIf($isNeg, $negBlock, $okBlock);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');

        $context->builder->positionAtEnd($negBlock);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $i8p = $context->getTypeFromString('int8*');
        $msgPtr = $context->builder->call(
            $context->lookupFunction('strerror'),
            $errnoI32
        );
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $msgPtr
        );
        $lenI64 = $context->builder->zExt($len, $i64);
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $msgPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $resultStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    public static function getLastError(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $i64->constInt(0, false);
    }

    private static function ensureLibcPid(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        foreach (['getpid', 'getppid'] as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable $e) {
                $ft = $context->context->functionType($i32, false);
                $fn = $context->module->addFunction($name, $ft);
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function ensureLibcStrerror(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        try {
            $context->lookupFunction('strerror');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($i8p, false, $i32);
            $fn = $context->module->addFunction('strerror', $ft);
            $context->registerFunction('strerror', $fn);
        }
    }
}
