<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\ext\standard\JitDate;
use PHPCompiler\JIT\Builtin\UnixtojdRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for unixtojd() via UnixtojdRuntime (#27367). */
final class JitUnixtojd
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'unixtojd() expects at most 1 argument, '.$argc.' given'
            );
        }

        // Omitted or null $timestamp → current time (php-src cal_unix.c; #24863).
        if (0 === $argc || self::isCompileTimeNull($args[0])) {
            $ts = JitDate::time($context);

            return UnixtojdRuntime::invoke($context, $ts);
        }

        $folded = self::tryFoldCompileTime($context, $args[0]);
        if (null !== $folded) {
            return $folded;
        }

        $timestamp = JitChr::lowerZParamLongArg($context, $args[0], 'unixtojd', 1, 'timestamp');

        return UnixtojdRuntime::invoke($context, $timestamp);
    }

    private static function tryFoldCompileTime(Context $context, JITVariable $arg): ?Value
    {
        $timestamp = self::compileTimeLongArg($arg);
        if (null === $timestamp) {
            return null;
        }

        try {
            $result = UnixtojdJitHelper::unixtojdArgv($timestamp);
        } catch (\ValueError) {
            // Negative timestamp: emit NestedJIT call so ValueError fires at run time.
            return null;
        }

        return $context->constantFromInteger($result, 'int64');
    }

    private static function compileTimeLongArg(JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeLong) {
            return $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            try {
                return (int) $arg->value->getConstantValue();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }
}
