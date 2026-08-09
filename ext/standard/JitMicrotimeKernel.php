<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for microtime() — thin libc gettimeofday (#29405 / #26930).
 *
 * Used while NestedJIT compiles {@see MicrotimeJitHelper} `@microtime` via
 * {@see \PHPCompiler\JIT\Builtin\StringMicrotime} (gethostname #29364 / getenv #29313 shape).
 * php-src: ext/standard/microtime.c — PHP_FUNCTION(microtime)
 */
final class JitMicrotimeKernel
{
    private const WALL_USEC = '__phpc_microtime_wall_usec';

    private const TIMEVAL_SIZE = 16;

    private const TIMEVAL_OFF_TV_SEC = 0;

    private const TIMEVAL_OFF_TV_USEC = 8;

    private const USEC_PER_SEC = 1_000_000;

    private const SNPRINTF_BUF = 64;

    /** @return Value double — seconds since epoch with fractional usec */
    public static function invokeFloat(Context $context): Value
    {
        self::ensureWallUsecHelper($context);
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $zero = $double->constReal(0.0);
        $usecPerSec = $i64->constInt(self::USEC_PER_SEC, false);

        $fn = $context->builder->getInsertBlock()->getParent();
        $raw = $context->builder->call($context->lookupFunction(self::WALL_USEC));
        $bad = $context->builder->icmp(Builder::INT_SLT, $raw, $i64->constInt(0, false));
        $failBb = $fn->appendBasicBlock('mt_nested_float_fail');
        $calcBb = $fn->appendBasicBlock('mt_nested_float_calc');
        $doneBb = $fn->appendBasicBlock('mt_nested_float_done');
        $context->builder->branchIf($bad, $failBb, $calcBb);

        $context->builder->positionAtEnd($failBb);
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($calcBb);
        $asDouble = $context->builder->sitofp($raw, $double);
        $divisor = $context->builder->sitofp($usecPerSec, $double);
        $okVal = $context->builder->fDiv($asDouble, $divisor);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($double, 'mt_nested_float');
        $phi->addIncoming($zero, $failEnd);
        $phi->addIncoming($okVal, $okEnd);

        return $phi;
    }

    /** @return Value `__string__*` — "frac sec" string form */
    public static function invokeString(Context $context): Value
    {
        self::ensureWallUsecHelper($context);
        self::ensureStringHelpers($context);

        $fn = $context->builder->getInsertBlock()->getParent();
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $double = $context->getTypeFromString('double');
        $usecPerSec = $i64->constInt(self::USEC_PER_SEC, false);
        $zeroStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(1, false),
            $context->builder->pointerCast($context->constantFromString('0'), $charPtr)
        );

        $raw = $context->builder->call($context->lookupFunction(self::WALL_USEC));
        $bad = $context->builder->icmp(Builder::INT_SLT, $raw, $i64->constInt(0, false));
        $failBb = $fn->appendBasicBlock('mt_nested_str_fail');
        $fmtBb = $fn->appendBasicBlock('mt_nested_str_fmt');
        $doneBb = $fn->appendBasicBlock('mt_nested_str_done');
        $context->builder->branchIf($bad, $failBb, $fmtBb);

        $context->builder->positionAtEnd($failBb);
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($fmtBb);
        $sec = $context->builder->signedDiv($raw, $usecPerSec);
        $usec = $context->builder->signedRem($raw, $usecPerSec);
        $buf = BasicBlockHelper::entryAllocaForFunction(
            $context,
            $fn,
            $i8->arrayType(self::SNPRINTF_BUF)
        );
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $usecD = $context->builder->sitofp($usec, $double);
        $divisor = $context->builder->sitofp($usecPerSec, $double);
        $frac = $context->builder->fDiv($usecD, $divisor);
        $fmtPtr = $context->builder->pointerCast(
            $context->constantFromString('%.8f %ld'),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $sizeT->constInt(self::SNPRINTF_BUF, false),
            $fmtPtr,
            $frac,
            $sec
        );
        $len = $context->builder->zExt($written, $i64);
        $okVal = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr, 'mt_nested_str');
        $phi->addIncoming($zeroStr, $failEnd);
        $phi->addIncoming($okVal, $okEnd);

        return $phi;
    }

    private static function ensureWallUsecHelper(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::WALL_USEC);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::WALL_USEC, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureLibcGettimeofday($context);
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::WALL_USEC,
                $context->context->functionType($i64, false)
            );

        $entry = $fn->appendBasicBlock('mt_wall_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI32 = $i32->constInt(0, false);
        $negOne = $i64->constInt(-1, true);
        $usecPerSec = $i64->constInt(self::USEC_PER_SEC, false);

        $tv = BasicBlockHelper::entryAllocaForFunction(
            $context,
            $fn,
            $i8->arrayType(self::TIMEVAL_SIZE)
        );
        $tvPtr = $context->builder->pointerCast($tv, $i8p);
        $status = $context->builder->call(
            $context->lookupFunction('gettimeofday'),
            $tvPtr,
            $i8p->constNull()
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $status, $zeroI32);
        $failBb = $fn->appendBasicBlock('mt_wall_fail');
        $okBb = $fn->appendBasicBlock('mt_wall_ok');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($negOne);

        $context->builder->positionAtEnd($okBb);
        $sec = self::loadI64At($context, $tv, self::TIMEVAL_OFF_TV_SEC);
        $usec = self::loadI64At($context, $tv, self::TIMEVAL_OFF_TV_USEC);
        $total = $context->builder->add(
            $context->builder->mul($sec, $usecPerSec),
            $usec
        );
        $context->builder->returnValue($total);
        $context->registerFunction(self::WALL_USEC, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function loadI64At(Context $context, Value $base, int $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $off = $i32->constInt($offset, false);
        $ptr = $context->builder->gep($base, $zero, $off);
        $slot = $context->builder->pointerCast($ptr, $i64->pointerType(0));

        return $context->builder->load($slot);
    }

    private static function ensureLibcGettimeofday(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        self::ensureExternal(
            $context,
            'gettimeofday',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
    }

    private static function ensureStringHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['__string__init', $strPtr, [$i64, $charPtr]],
            ['snprintf', $i32, [$charPtr, $sizeT, $charPtr], true],
        ] as $spec) {
            $variadic = $spec[3] ?? false;
            self::ensureExternal(
                $context,
                $spec[0],
                $context->context->functionType($spec[1], $variadic, ...$spec[2])
            );
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
