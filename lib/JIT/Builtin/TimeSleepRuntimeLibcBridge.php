<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Emit-helper / bootstrap link: libc LLVM bridges when ext/* JIT bodies are stubbed (#9068).
 *
 * Mirrors lib/AOT/runtime/phpc_time_sleep.c (issue #5180, #5406).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(time_nanosleep), time_sleep_until
 */
final class TimeSleepRuntimeLibcBridge
{
    private const TIMESPEC_SIZE = 16;

    private const TIMEVAL_SIZE = 16;

    private const TIMESPEC_OFF_TV_SEC = 0;

    private const TIMESPEC_OFF_TV_NSEC = 8;

    private const TIMEVAL_OFF_TV_SEC = 0;

    private const TIMEVAL_OFF_TV_USEC = 8;

    private const NS_PER_SEC = 1000000000;

    private const EINTR = 4;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $nanosleepProbe = $context->module->getNamedFunction('__compiler_time_nanosleep');
        if (null !== $nanosleepProbe && $nanosleepProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibcTime($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $doubleTy = $context->getTypeFromString('double');

        $ftNanosleep = $context->context->functionType($i32, false, $i64, $i64);
        $fnNanosleep = null !== $nanosleepProbe
            ? $nanosleepProbe
            : $context->module->addFunction('__compiler_time_nanosleep', $ftNanosleep);
        self::implementTimeNanosleep($context, $fnNanosleep);

        $untilProbe = $context->module->getNamedFunction('__compiler_time_sleep_until');
        $ftUntil = $context->context->functionType($i32, false, $doubleTy);
        $fnUntil = null !== $untilProbe
            ? $untilProbe
            : $context->module->addFunction('__compiler_time_sleep_until', $ftUntil);
        self::implementTimeSleepUntil($context, $fnUntil);

        self::registerLinkedRuntime($context);
    }

    private static function implementTimeNanosleep(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('tn_entry');
        $context->builder->positionAtEnd($entry);

        $sec = $fn->getParam(0);
        $nsec = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');

        $req = $context->builder->alloca($i8, self::TIMESPEC_SIZE, 'tn_req');
        $rem = $context->builder->alloca($i8, self::TIMESPEC_SIZE, 'tn_rem');
        self::storeTimespec($context, $req, $sec, $nsec);

        $ok = self::nanosleepLoop($context, $fn, $req, $rem);
        $context->builder->returnValue($ok);

        $context->builder->clearInsertionPosition();
    }

    private static function implementTimeSleepUntil(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('tsu_entry');
        $context->builder->positionAtEnd($entry);

        $targetSecs = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $doubleTy = $context->getTypeFromString('double');
        $zeroI32 = $i32->constInt(0, false);
        $nsPerSecD = $doubleTy->constReal((float) self::NS_PER_SEC);
        $nsPerSecI64 = $i64->constInt(self::NS_PER_SEC, false);
        $usecScale = $i64->constInt(1000, false);

        $tv = $context->builder->alloca($i8, self::TIMEVAL_SIZE, 'tsu_tv');
        $tvPtr = $context->builder->pointerCast($tv, $i8p);
        $gtvRet = $context->builder->call(
            $context->lookupFunction('gettimeofday'),
            $tvPtr,
            $i8p->constNull()
        );
        $gtvFail = $context->builder->icmp(Builder::INT_NE, $gtvRet, $zeroI32);
        $failBb = $fn->appendBasicBlock('tsu_gtv_fail');
        $pastBb = $fn->appendBasicBlock('tsu_past_check');
        $context->builder->branchIf($gtvFail, $failBb, $pastBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zeroI32);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($pastBb);
        $tvSec = self::loadI64At($context, $tv, self::TIMEVAL_OFF_TV_SEC);
        $tvUsec = self::loadI64At($context, $tv, self::TIMEVAL_OFF_TV_USEC);
        $targetNsD = $context->builder->fmul($targetSecs, $nsPerSecD);
        $targetNs = $context->builder->fpToUi($targetNsD, $i64);
        $currentNs = $context->builder->add(
            $context->builder->mul($tvSec, $nsPerSecI64),
            $context->builder->mul($tvUsec, $usecScale)
        );
        $inPast = $context->builder->icmp(Builder::INT_SLT, $targetNs, $currentNs);
        $sleepBb = $fn->appendBasicBlock('tsu_sleep');
        $context->builder->branchIf($inPast, $failBb, $sleepBb);

        $context->builder->positionAtEnd($sleepBb);
        $diffNs = $context->builder->sub($targetNs, $currentNs);
        $sleepSec = $context->builder->unsignedDiv($diffNs, $nsPerSecI64);
        $sleepNsec = $context->builder->unsigendRem($diffNs, $nsPerSecI64);

        $req = $context->builder->alloca($i8, self::TIMESPEC_SIZE, 'tsu_req');
        $rem = $context->builder->alloca($i8, self::TIMESPEC_SIZE, 'tsu_rem');
        self::storeTimespec($context, $req, $sleepSec, $sleepNsec);

        $ok = self::nanosleepLoop($context, $fn, $req, $rem);
        $context->builder->returnValue($ok);

        $context->builder->clearInsertionPosition();
    }

    private static function nanosleepLoop(Context $context, Value $fn, Value $req, Value $rem): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $eintr = $i32->constInt(self::EINTR, false);
        $reqPtr = $context->builder->pointerCast($req, $i8p);
        $remPtr = $context->builder->pointerCast($rem, $i8p);

        $loopHead = $fn->appendBasicBlock('tn_loop_head');
        $loopOk = $fn->appendBasicBlock('tn_loop_ok');
        $loopRetry = $fn->appendBasicBlock('tn_loop_retry');
        $loopFail = $fn->appendBasicBlock('tn_loop_fail');

        $context->builder->branch($loopHead);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($loopHead);
        $nsRet = $context->builder->call(
            $context->lookupFunction('nanosleep'),
            $reqPtr,
            $remPtr
        );
        $nsOk = $context->builder->icmp(Builder::INT_EQ, $nsRet, $zeroI32);
        $context->builder->branchIf($nsOk, $loopOk, $loopRetry);

        $mergeBb = $fn->appendBasicBlock('tn_loop_merge');

        $context->builder->positionAtEnd($loopOk);
        $context->builder->branch($mergeBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($loopRetry);
        $errnoPtr = $context->builder->call($context->lookupFunction('__errno_location'));
        $errnoVal = $context->builder->load($errnoPtr);
        $isEintr = $context->builder->icmp(Builder::INT_EQ, $errnoVal, $eintr);
        $context->builder->branchIf($isEintr, $loopCopyBb = $fn->appendBasicBlock('tn_loop_copy'), $loopFail);

        $context->builder->positionAtEnd($loopCopyBb);
        self::copyTimespec($context, $req, $rem);
        $context->builder->branch($loopHead);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($loopFail);
        $context->builder->branch($mergeBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i32);
        $phi->addIncoming($oneI32, $loopOk);
        $phi->addIncoming($zeroI32, $loopFail);

        return $phi;
    }

    private static function storeTimespec(Context $context, Value $slot, Value $sec, Value $nsec): void
    {
        self::storeI64At($context, $slot, self::TIMESPEC_OFF_TV_SEC, $sec);
        self::storeI64At($context, $slot, self::TIMESPEC_OFF_TV_NSEC, $nsec);
    }

    private static function copyTimespec(Context $context, Value $dst, Value $src): void
    {
        $sec = self::loadI64At($context, $src, self::TIMESPEC_OFF_TV_SEC);
        $nsec = self::loadI64At($context, $src, self::TIMESPEC_OFF_TV_NSEC);
        self::storeTimespec($context, $dst, $sec, $nsec);
    }

    private static function storeI64At(Context $context, Value $base, int $offset, Value $val): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ptr = $context->builder->gep($base, $i8->constInt($offset, false));
        $slot = $context->builder->pointerCast($ptr, $i64->pointerType(0));
        $context->builder->store($val, $slot);
    }

    private static function loadI64At(Context $context, Value $base, int $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ptr = $context->builder->gep($base, $i8->constInt($offset, false));
        $slot = $context->builder->pointerCast($ptr, $i64->pointerType(0));

        return $context->builder->load($slot);
    }

    private static function ensureLibcTime(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $doubleTy = $context->getTypeFromString('double');
        $i32Ptr = $i32->pointerType(0);

        self::ensureExternal(
            $context,
            'nanosleep',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
        self::ensureExternal(
            $context,
            'gettimeofday',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
        self::ensureExternal(
            $context,
            '__errno_location',
            $context->context->functionType($i32Ptr, false)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_time_nanosleep', '__compiler_time_sleep_until'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after TimeSleepRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
