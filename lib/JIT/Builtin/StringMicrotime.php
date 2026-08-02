<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_microtime_string / __compiler_microtime_float (#6110, #26930).
 *
 * Thin AOT NestedJIT of MicrotimeJitHelper orphans the caller insert block
 * (peer #26900 / #26929). Emit gettimeofday + snprintf in LLVM.
 *
 * gettimeofday runs only in an i64-returning wall helper — calling it from a
 * double-returning bridge SEGV under thin AOT (#26930 float path).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmDate::microtime()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(microtime)
 */
final class StringMicrotime
{
    private const TIMEVAL_SIZE = 16;

    private const TIMEVAL_OFF_TV_SEC = 0;

    private const TIMEVAL_OFF_TV_USEC = 8;

    private const USEC_PER_SEC = 1_000_000;

    private const SNPRINTF_BUF = 64;

    private const WALL_USEC = '__phpc_microtime_wall_usec';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $strProbe = $context->module->getNamedFunction('__compiler_microtime_string');
        if (null !== $strProbe && $strProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit
        // (microtime thin AOT: "Current basic block has no parent function", #26930).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureLibcGettimeofday($context);
        self::ensureStringHelpers($context);
        self::implementWallUsecHelper($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $double = $context->getTypeFromString('double');

        $ftStr = $context->context->functionType($strPtr, false);
        $fnStr = null !== $strProbe
            ? $strProbe
            : $context->module->addFunction('__compiler_microtime_string', $ftStr);
        self::implementMicrotimeString($context, $fnStr);

        $floatProbe = $context->module->getNamedFunction('__compiler_microtime_float');
        $ftFloat = $context->context->functionType($double, false);
        $fnFloat = null !== $floatProbe
            ? $floatProbe
            : $context->module->addFunction('__compiler_microtime_float', $ftFloat);
        self::implementMicrotimeFloat($context, $fnFloat);

        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * i64 microseconds since epoch (−1 on failure). Owns gettimeofday so the
     * double-returning float bridge never calls libc directly (#26930).
     */
    private static function implementWallUsecHelper(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::WALL_USEC);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::WALL_USEC, $probe);

            return;
        }

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
    }

    private static function implementMicrotimeFloat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('mt_float_entry');
        $context->builder->positionAtEnd($entry);

        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $zero = $double->constReal(0.0);
        $negOne = $i64->constInt(-1, true);
        $usecPerSec = $i64->constInt(self::USEC_PER_SEC, false);

        $raw = $context->builder->call($context->lookupFunction(self::WALL_USEC));
        $bad = $context->builder->icmp(Builder::INT_SLT, $raw, $i64->constInt(0, false));
        $failBb = $fn->appendBasicBlock('mt_float_fail');
        $calcBb = $fn->appendBasicBlock('mt_float_calc');
        $context->builder->branchIf($bad, $failBb, $calcBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($calcBb);
        $asDouble = $context->builder->sitofp($raw, $double);
        $divisor = $context->builder->sitofp($usecPerSec, $double);
        $context->builder->returnValue($context->builder->fDiv($asDouble, $divisor));
    }

    private static function implementMicrotimeString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('mt_str_entry');
        $context->builder->positionAtEnd($entry);

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
        $failBb = $fn->appendBasicBlock('mt_str_fail');
        $fmtBb = $fn->appendBasicBlock('mt_str_fmt');
        $context->builder->branchIf($bad, $failBb, $fmtBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zeroStr);

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
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->returnValue($result);
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_microtime_string', '__compiler_microtime_float'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringMicrotime LLVM implement (#26930)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
