<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\FsDirJitHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM libc utime(2) body for __compiler_touch (#28995).
 *
 * NestedJIT {@see FsDirJitHelper::touch} cannot set times under thin AOT: host
 * \\touch() re-enters __compiler_touch, and FFI is unavailable in the native
 * binary. Platform utime is the justified thin ABI (php-src VCWD_UTIME).
 *
 * Omit sentinel: {@see FsDirJitHelper::TOUCH_TIME_OMIT} (PHP_INT_MIN) — not
 * “any negative”, so explicit -1 mtime/atime works (#11587).
 *
 * php-src: ext/standard/filestat.c — php_touch
 */
final class TouchLibcRuntime
{
    private const O_WRONLY_CREAT_TRUNC = 577;

    private const STAT_BUF_SIZE = 144;

    public static function emit(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('touch_libc_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');

        $path = $fn->getParam(0);
        $mtime = $fn->getParam(1);
        $atime = $fn->getParam(2);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $omit = $i64->constInt(FsDirJitHelper::TOUCH_TIME_OMIT, true);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $fail = $fn->appendBasicBlock('touch_libc_fail');
        $checkPath = $fn->appendBasicBlock('touch_libc_check_path');
        $context->builder->branchIf($isNull, $fail, $checkPath);

        $context->builder->positionAtEnd($checkPath);
        $p = self::stringData($context, $path);
        $stSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::STAT_BUF_SIZE));
        $stBase = self::stackBytesPtr($context, $stSlot);
        $stRc = $context->builder->call($context->lookupFunction('stat'), $p, $stBase);
        $needCreate = $context->builder->icmp(Builder::INT_NE, $stRc, $zero);
        $openBlock = $fn->appendBasicBlock('touch_libc_open');
        $afterOpen = $fn->appendBasicBlock('touch_libc_after_open');
        $context->builder->branchIf($needCreate, $openBlock, $afterOpen);

        $context->builder->positionAtEnd($openBlock);
        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $p,
            $i32->constInt(self::O_WRONLY_CREAT_TRUNC, false),
            $i32->constInt(0666, false)
        );
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $zero);
        $closeBlock = $fn->appendBasicBlock('touch_libc_close_fd');
        $context->builder->branchIf($fdBad, $fail, $closeBlock);
        $context->builder->positionAtEnd($closeBlock);
        $closeRc = $context->builder->call($context->lookupFunction('close'), $fd);
        $closeBad = $context->builder->icmp(Builder::INT_NE, $closeRc, $zero);
        $context->builder->branchIf($closeBad, $fail, $afterOpen);

        $context->builder->positionAtEnd($afterOpen);
        $mtimeOmit = $context->builder->icmp(Builder::INT_EQ, $mtime, $omit);
        $atimeOmit = $context->builder->icmp(Builder::INT_EQ, $atime, $omit);
        $bothOmit = $context->builder->and($mtimeOmit, $atimeOmit);
        $utimeNow = $fn->appendBasicBlock('touch_libc_utime_now');
        $custom = $fn->appendBasicBlock('touch_libc_custom');
        $context->builder->branchIf($bothOmit, $utimeNow, $custom);

        $context->builder->positionAtEnd($utimeNow);
        $utNowRc = $context->builder->call($context->lookupFunction('utime'), $p, $i8p->constNull());
        $utNowOk = $context->builder->icmp(Builder::INT_EQ, $utNowRc, $zero);
        $context->builder->returnValue($context->builder->select($utNowOk, $one, $zero));

        $context->builder->positionAtEnd($custom);
        $now = $context->builder->call(
            $context->lookupFunction('time'),
            $context->getTypeFromString('int8*')->constNull()
        );
        $mtimeEff = $context->builder->select($mtimeOmit, $now, $mtime);
        // php-src: omitted atime uses effective mtime (2-arg touch).
        $atimeEff = $context->builder->select($atimeOmit, $mtimeEff, $atime);
        $times = BasicBlockHelper::entryAlloca($context, $i64->arrayType(2));
        $context->builder->store(
            $atimeEff,
            $context->builder->inBoundsGEP($times, $i32->constInt(0, false), $i64->constInt(0, false))
        );
        $context->builder->store(
            $mtimeEff,
            $context->builder->inBoundsGEP($times, $i32->constInt(0, false), $i64->constInt(1, false))
        );
        $utRc = $context->builder->call(
            $context->lookupFunction('utime'),
            $p,
            self::stackBytesPtr($context, $times)
        );
        $utOk = $context->builder->icmp(Builder::INT_EQ, $utRc, $zero);
        $context->builder->returnValue($context->builder->select($utOk, $one, $zero));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero);
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['stat', $i32, [$i8p, $i8p]],
                ['open', $i32, [$i8p, $i32, $i32]],
                ['close', $i32, [$i32]],
                ['utime', $i32, [$i8p, $i8p]],
                ['time', $i64, [$i8p]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function stackBytesPtr(Context $context, Value $slot): Value
    {
        return $context->builder->pointerCast($slot, $context->getTypeFromString('int8*'));
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }
}
