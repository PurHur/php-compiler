<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\RangeIntRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * range() — int/float/char bounds (php-src ext/standard/array.c; #4258 VM, #27563 AOT char, #27158 AOT float).
 */
final class range extends Internal
{
    private static int $jitGuardSeq = 0;

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2 || \count($frame->calledArgs) > 3) {
            throw new \LogicException('range() requires two or three arguments');
        }
        $stepVar = 3 === \count($frame->calledArgs) ? $frame->calledArgs[2] : null;
        if (null === $frame->returnVar) {
            VmRange::build($frame, $frame->calledArgs[0], $frame->calledArgs[1], $stepVar);

            return;
        }
        $frame->returnVar->array(
            VmRange::build($frame, $frame->calledArgs[0], $frame->calledArgs[1], $stepVar)
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('range() requires two or three arguments');
        }

        // php-src: float path when any bound/step is double — before char (#24399 / #27158).
        if (self::jitPrefersFloat($args[0])
            || self::jitPrefersFloat($args[1])
            || (3 === \count($args) && self::jitPrefersFloat($args[2]))) {
            return self::callFloatRange($context, $args);
        }

        $startChar = self::charLetterLiteral($args[0]);
        $endChar = self::charLetterLiteral($args[1]);
        if (null !== $startChar && null !== $endChar) {
            // php-src php_range_process_input multi-byte warn then first-byte path (#29203 / #28830).
            self::emitMultiByteCharLiteralWarnings($context, $args[0], $args[1]);

            return self::callCharRange($context, $startChar, $endChar, $args);
        }

        if (!self::jitIntEndpointOk($args[0]) || !self::jitIntEndpointOk($args[1])) {
            throw new \LogicException('range() start and end must be integers in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $start = self::lowerIntEndpointArg($context, $args[0], 1, 'start');
        $end = self::lowerIntEndpointArg($context, $args[1], 2, 'end');
        if (3 === \count($args)) {
            $step = self::lowerIntStepArg($context, $args[2]);
        } else {
            $cmp = $context->builder->icmp(Builder::INT_SGT, $start, $end);
            $one = $i64->constInt(1, false);
            $negOne = $i64->constInt(-1, false);
            $step = $context->builder->select($cmp, $negOne, $one);
        }
        self::emitZeroStepGuard($context, $step);
        self::emitIncreasingNegativeStepGuard($context, $start, $end, $step);
        self::emitOversizedStepGuard($context, $start, $end, $step);

        return RangeIntRuntime::intRange($context, $start, $end, $step);
    }

    /**
     * Float bounds/step (#27158) — coerce native long/double to double, match VmRange float path.
     *
     * @param list<JITVariable> $args
     */
    private static function callFloatRange(Context $context, array $args): Value
    {
        foreach ($args as $i => $arg) {
            // Soft-null $start/$end (#29348); soft-null $step (#29352); bool $step coerces (#29505).
            if (self::jitIsNullArg($arg)
                || (2 === $i && JITVariable::TYPE_NATIVE_BOOL === $arg->type)) {
                continue;
            }
            if (JITVariable::TYPE_NATIVE_LONG !== $arg->type
                && JITVariable::TYPE_NATIVE_DOUBLE !== $arg->type) {
                throw new \LogicException('range() float path requires native numeric operands in this compiler build');
            }
        }
        $double = $context->getTypeFromString('double');
        $start = self::jitIsNullArg($args[0])
            ? self::lowerFloatEndpointNull($context, 1, 'start')
            : self::toJitDouble($context, $args[0], $double);
        $end = self::jitIsNullArg($args[1])
            ? self::lowerFloatEndpointNull($context, 2, 'end')
            : self::toJitDouble($context, $args[1], $double);
        if (3 === \count($args)) {
            if (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
                $step = self::lowerFloatStepNull($context);
            } elseif (JITVariable::TYPE_NATIVE_BOOL === $args[2]->type) {
                // Z_PARAM_NUMBER bool → 0.0/1.0 (#29505).
                $step = $context->builder->uiToFp($context->helper->loadValue($args[2]), $double);
            } else {
                $step = self::toJitDouble($context, $args[2], $double);
            }
        } else {
            $cmp = $context->builder->fcmp(Builder::REAL_OGT, $start, $end);
            $one = $double->constReal(1.0);
            $negOne = $double->constReal(-1.0);
            $step = $context->builder->select($cmp, $negOne, $one);
        }
        self::emitZeroFloatStepGuard($context, $step);
        self::emitIncreasingNegativeFloatStepGuard($context, $start, $end, $step);
        self::emitOversizedFloatStepGuard($context, $start, $end, $step);

        return RangeIntRuntime::floatRange($context, $start, $end, $step);
    }

    private static function jitPrefersFloat(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NATIVE_DOUBLE === $arg->type;
    }

    private static function toJitDouble(Context $context, JITVariable $arg, $double): Value
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->sitofp($context->helper->loadValue($arg), $double);
    }

    /**
     * php-src char path: non-numeric string endpoints via first byte (VmRange::charRangeLetter; #28830).
     *
     * @param list<JITVariable> $args
     */
    private static function callCharRange(
        Context $context,
        string $startChar,
        string $endChar,
        array $args
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $start = $i64->constInt(\ord($startChar), false);
        $end = $i64->constInt(\ord($endChar), false);
        if (3 === \count($args)) {
            $step = self::lowerIntStepArg($context, $args[2]);
        } else {
            $cmp = $context->builder->icmp(Builder::INT_SGT, $start, $end);
            $one = $i64->constInt(1, false);
            $negOne = $i64->constInt(-1, false);
            $step = $context->builder->select($cmp, $negOne, $one);
        }
        self::emitZeroStepGuard($context, $step);
        self::emitIncreasingNegativeStepGuard($context, $start, $end, $step);
        self::emitOversizedStepGuard($context, $start, $end, $step);

        return RangeIntRuntime::charRange($context, $start, $end, $step);
    }

    private static function jitIsNullArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    private static function jitIntEndpointOk(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NATIVE_LONG === $arg->type || self::jitIsNullArg($arg);
    }

    /**
     * Z_PARAM_STR_OR_LONG soft-null on $start/$end — DEP (string|int|float) then 0 (#29348).
     * Caller strict_types → TypeError (Zend).
     */
    private static function lowerIntEndpointArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if (self::jitIsNullArg($arg)) {
            if ($context->callerStrictTypes) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    sprintf(
                        'range(): Argument #%d ($%s) must be of type string|int|float, null given',
                        $argIndex,
                        $paramName
                    )
                );

                return $context->getTypeFromString('int64')->constInt(0, false);
            }
            // Skip DEP IR on user-script AOT (thin standalone trigger_error mid-fold — peer #21593 / #29352).
            if (!$context->isUserScriptAot()) {
                JitIntdiv::emitNullIntDeprecation(
                    $context,
                    'range',
                    $argIndex,
                    $paramName,
                    'string|int|float'
                );
            }

            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return JitLongArg::lower($context, $arg, 'range() '.$paramName);
    }

    /** Soft-null float $start/$end → 0.0 with string|int|float DEP (#29348). */
    private static function lowerFloatEndpointNull(
        Context $context,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                sprintf(
                    'range(): Argument #%d ($%s) must be of type string|int|float, null given',
                    $argIndex,
                    $paramName
                )
            );

            return $context->getTypeFromString('double')->constReal(0.0);
        }
        if (!$context->isUserScriptAot()) {
            JitIntdiv::emitNullIntDeprecation(
                $context,
                'range',
                $argIndex,
                $paramName,
                'string|int|float'
            );
        }

        return $context->getTypeFromString('double')->constReal(0.0);
    }

    /**
     * Z_PARAM_NUMBER soft-null on $step — DEP (int|float) then 0; zero-step guard follows (#29352).
     * Caller strict_types → TypeError (Zend).
     */
    private static function lowerIntStepArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'range(): Argument #3 ($step) must be of type int|float, null given'
                );

                return $context->getTypeFromString('int64')->constInt(0, false);
            }
            // Skip DEP IR on user-script AOT (thin standalone trigger_error mid-fold — peer #21593).
            if (!$context->isUserScriptAot()) {
                JitIntdiv::emitNullIntDeprecation($context, 'range', 3, 'step', 'int|float');
            }

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        // Z_PARAM_NUMBER: bool → 0/1 via JitLongArg zext (#29505); long/double as before.
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type
            && JITVariable::TYPE_NATIVE_BOOL !== $arg->type
            && JITVariable::TYPE_NATIVE_DOUBLE !== $arg->type) {
            throw new \LogicException('range() step must be an integer in this compiler build');
        }

        return JitLongArg::lower($context, $arg, 'range() step');
    }

    /** Soft-null float $step → 0.0 with int|float DEP (#29352). */
    private static function lowerFloatStepNull(Context $context): Value
    {
        if ($context->callerStrictTypes) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'range(): Argument #3 ($step) must be of type int|float, null given'
            );

            return $context->getTypeFromString('double')->constReal(0.0);
        }
        if (!$context->isUserScriptAot()) {
            JitIntdiv::emitNullIntDeprecation($context, 'range', 3, 'step', 'int|float');
        }

        return $context->getTypeFromString('double')->constReal(0.0);
    }

    /**
     * Match {@see VmRange} charRangeLetter for compile-time string literals (#27563, #28830).
     * Non-numeric multi-byte literals use byte 0 (php-src Z_STRVAL[0] char path).
     */
    private static function charLetterLiteral(JITVariable $arg): ?string
    {
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null === $lit || '' === $lit || is_numeric($lit)) {
            return null;
        }

        return $lit[0];
    }

    /**
     * Emit Zend 8.3+ single-byte warnings for multi-byte char-bound literals (#29203).
     */
    private static function emitMultiByteCharLiteralWarnings(
        Context $context,
        JITVariable $startArg,
        JITVariable $endArg
    ): void {
        if (!CompilerVersion::supportsRangeSingleByteStringWarning()) {
            return;
        }
        $startLit = JitStringArg::compileTimeLiteral($startArg);
        $endLit = JitStringArg::compileTimeLiteral($endArg);
        if (null !== $startLit && \strlen($startLit) > 1) {
            self::emitRangeSingleByteWarning($context, 1, 'start');
        }
        if (null !== $endLit && \strlen($endLit) > 1) {
            self::emitRangeSingleByteWarning($context, 2, 'end');
        }
    }

    private static function emitRangeSingleByteWarning(Context $context, int $argIndex, string $paramName): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $message = \sprintf(
            'range(): Argument #%d ($%s) must be a single byte, subsequent bytes are ignored',
            $argIndex,
            $paramName
        );
        $msg = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msg,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
    }

    private static function emitZeroStepGuard(Context $context, Value $step): void
    {
        $tag = 'rs'.(string) ++self::$jitGuardSeq;
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $step, $zero);
        $ok = BasicBlockHelper::append($context, 'range_step_ok_'.$tag);
        $err = BasicBlockHelper::append($context, 'range_step_err_'.$tag);
        $context->builder->branchIf($isZero, $err, $ok);
        $context->builder->positionAtEnd($err);
        ExceptionBridge::emitValueErrorAndAbort($context, RangeIntJitHelper::stepZeroErrorMessage());
        BasicBlockHelper::ensureOpenInsertBlock($context, 'range_step_err_dead_'.$tag);
        $context->builder->positionAtEnd($ok);
    }

    private static function emitZeroFloatStepGuard(Context $context, Value $step): void
    {
        $tag = 'rsf'.(string) ++self::$jitGuardSeq;
        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);
        $isZero = $context->builder->fcmp(Builder::REAL_OEQ, $step, $zero);
        $ok = BasicBlockHelper::append($context, 'range_fstep_ok_'.$tag);
        $err = BasicBlockHelper::append($context, 'range_fstep_err_'.$tag);
        $context->builder->branchIf($isZero, $err, $ok);
        $context->builder->positionAtEnd($err);
        ExceptionBridge::emitValueErrorAndAbort($context, RangeIntJitHelper::stepZeroErrorMessage());
        BasicBlockHelper::ensureOpenInsertBlock($context, 'range_fstep_err_dead_'.$tag);
        $context->builder->positionAtEnd($ok);
    }

    /**
     * php-src negative_step_error: strictly increasing range + negative step (#29351).
     * PROFILE≥8.3 only — legacy silently flips the sign.
     */
    private static function emitIncreasingNegativeStepGuard(
        Context $context,
        Value $start,
        Value $end,
        Value $step
    ): void {
        if (!CompilerVersion::supportsRangeIncreasingNegativeStepError()) {
            return;
        }
        $tag = 'rin'.(string) ++self::$jitGuardSeq;
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $increasing = $context->builder->icmp(Builder::INT_SLT, $start, $end);
        $stepNeg = $context->builder->icmp(Builder::INT_SLT, $step, $zero);
        $bad = $context->builder->and($increasing, $stepNeg);
        $ok = BasicBlockHelper::append($context, 'range_inc_neg_ok_'.$tag);
        $err = BasicBlockHelper::append($context, 'range_inc_neg_err_'.$tag);
        $context->builder->branchIf($bad, $err, $ok);
        $context->builder->positionAtEnd($err);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            RangeIntJitHelper::stepIncreasingNegativeErrorMessage()
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'range_inc_neg_err_dead_'.$tag);
        $context->builder->positionAtEnd($ok);
    }

    /**
     * Float twin of {@see emitIncreasingNegativeStepGuard} (#29351).
     */
    private static function emitIncreasingNegativeFloatStepGuard(
        Context $context,
        Value $start,
        Value $end,
        Value $step
    ): void {
        if (!CompilerVersion::supportsRangeIncreasingNegativeStepError()) {
            return;
        }
        $tag = 'rif'.(string) ++self::$jitGuardSeq;
        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);
        $increasing = $context->builder->fcmp(Builder::REAL_OLT, $start, $end);
        $stepNeg = $context->builder->fcmp(Builder::REAL_OLT, $step, $zero);
        $bad = $context->builder->and($increasing, $stepNeg);
        $ok = BasicBlockHelper::append($context, 'range_finc_neg_ok_'.$tag);
        $err = BasicBlockHelper::append($context, 'range_finc_neg_err_'.$tag);
        $context->builder->branchIf($bad, $err, $ok);
        $context->builder->positionAtEnd($err);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            RangeIntJitHelper::stepIncreasingNegativeErrorMessage()
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'range_finc_neg_err_dead_'.$tag);
        $context->builder->positionAtEnd($ok);
    }

    /**
     * php-src PHP_FUNCTION(range): when endpoints differ, |step| must be <= |end-start| (#26657).
     * Emit before RangeIntRuntime so AOT/JIT raise ValueError without relying on PHP helper throws.
     */
    private static function emitOversizedStepGuard(
        Context $context,
        Value $start,
        Value $end,
        Value $step
    ): void {
        $tag = 'rb'.(string) ++self::$jitGuardSeq;
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $diff = $context->builder->sub($end, $start);
        $diffNeg = $context->builder->icmp(Builder::INT_SLT, $diff, $zero);
        $diffAbs = $context->builder->select(
            $diffNeg,
            $context->builder->sub($zero, $diff),
            $diff
        );
        $stepNeg = $context->builder->icmp(Builder::INT_SLT, $step, $zero);
        $stepAbs = $context->builder->select(
            $stepNeg,
            $context->builder->sub($zero, $step),
            $step
        );
        $endpointsDiffer = $context->builder->icmp(Builder::INT_NE, $start, $end);
        $stepTooBig = $context->builder->icmp(Builder::INT_ULT, $diffAbs, $stepAbs);
        $bad = $context->builder->and($endpointsDiffer, $stepTooBig);
        $ok = BasicBlockHelper::append($context, 'range_span_ok_'.$tag);
        $err = BasicBlockHelper::append($context, 'range_span_err_'.$tag);
        $context->builder->branchIf($bad, $err, $ok);
        $context->builder->positionAtEnd($err);
        ExceptionBridge::emitValueErrorAndAbort($context, RangeIntJitHelper::stepOversizedErrorMessage());
        BasicBlockHelper::ensureOpenInsertBlock($context, 'range_span_err_dead_'.$tag);
        $context->builder->positionAtEnd($ok);
    }

    private static function emitOversizedFloatStepGuard(
        Context $context,
        Value $start,
        Value $end,
        Value $step
    ): void {
        $tag = 'rbf'.(string) ++self::$jitGuardSeq;
        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);
        $diff = $context->builder->fsub($end, $start);
        $diffNeg = $context->builder->fcmp(Builder::REAL_OLT, $diff, $zero);
        $diffAbs = $context->builder->select(
            $diffNeg,
            $context->builder->fsub($zero, $diff),
            $diff
        );
        $stepNeg = $context->builder->fcmp(Builder::REAL_OLT, $step, $zero);
        $stepAbs = $context->builder->select(
            $stepNeg,
            $context->builder->fsub($zero, $step),
            $step
        );
        $endpointsDiffer = $context->builder->fcmp(Builder::REAL_ONE, $start, $end);
        $stepTooBig = $context->builder->fcmp(Builder::REAL_OLT, $diffAbs, $stepAbs);
        $bad = $context->builder->and($endpointsDiffer, $stepTooBig);
        $ok = BasicBlockHelper::append($context, 'range_fspan_ok_'.$tag);
        $err = BasicBlockHelper::append($context, 'range_fspan_err_'.$tag);
        $context->builder->branchIf($bad, $err, $ok);
        $context->builder->positionAtEnd($err);
        ExceptionBridge::emitValueErrorAndAbort($context, RangeIntJitHelper::stepOversizedErrorMessage());
        BasicBlockHelper::ensureOpenInsertBlock($context, 'range_fspan_err_dead_'.$tag);
        $context->builder->positionAtEnd($ok);
    }

}
