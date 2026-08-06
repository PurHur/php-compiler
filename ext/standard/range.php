<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Builtin\RangeIntRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * range() — int/float/char bounds (php-src ext/standard/array.c; #4258 VM, #27563 AOT char, #27158 AOT float).
 */
final class range extends Internal
{
    private const STEP_RANGE_ERROR = 'range(): Argument #3 ($step) must not exceed the specified range';

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
            return self::callCharRange($context, $startChar, $endChar, $args);
        }

        if (JITVariable::TYPE_NATIVE_LONG !== $args[0]->type
            || JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('range() start and end must be integers in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $start = JitLongArg::lower($context, $args[0], 'range() start');
        $end = JitLongArg::lower($context, $args[1], 'range() end');
        if (3 === \count($args)) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                throw new \LogicException('range() step must be an integer in this compiler build');
            }
            $step = JitLongArg::lower($context, $args[2], 'range() step');
        } else {
            $cmp = $context->builder->icmp(Builder::INT_SGT, $start, $end);
            $one = $i64->constInt(1, false);
            $negOne = $i64->constInt(-1, false);
            $step = $context->builder->select($cmp, $negOne, $one);
        }
        self::emitZeroStepGuard($context, $step);
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
        foreach ($args as $arg) {
            if (JITVariable::TYPE_NATIVE_LONG !== $arg->type
                && JITVariable::TYPE_NATIVE_DOUBLE !== $arg->type) {
                throw new \LogicException('range() float path requires native numeric operands in this compiler build');
            }
        }
        $double = $context->getTypeFromString('double');
        $start = self::toJitDouble($context, $args[0], $double);
        $end = self::toJitDouble($context, $args[1], $double);
        if (3 === \count($args)) {
            $step = self::toJitDouble($context, $args[2], $double);
        } else {
            $cmp = $context->builder->fcmp(Builder::REAL_OGT, $start, $end);
            $one = $double->constReal(1.0);
            $negOne = $double->constReal(-1.0);
            $step = $context->builder->select($cmp, $negOne, $one);
        }
        self::emitZeroFloatStepGuard($context, $step);
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
     * php-src char path: single non-numeric letter endpoints (VmRange::charRangeLetter).
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
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                throw new \LogicException('range() step must be an integer in this compiler build');
            }
            $step = JitLongArg::lower($context, $args[2], 'range() step');
        } else {
            $cmp = $context->builder->icmp(Builder::INT_SGT, $start, $end);
            $one = $i64->constInt(1, false);
            $negOne = $i64->constInt(-1, false);
            $step = $context->builder->select($cmp, $negOne, $one);
        }
        self::emitZeroStepGuard($context, $step);
        self::emitOversizedStepGuard($context, $start, $end, $step);

        return RangeIntRuntime::charRange($context, $start, $end, $step);
    }

    /** Match {@see VmRange} charRangeLetter for compile-time string literals (#27563). */
    private static function charLetterLiteral(JITVariable $arg): ?string
    {
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null === $lit) {
            return null;
        }
        if (1 !== \strlen($lit)) {
            return null;
        }
        if (is_numeric($lit)) {
            return null;
        }

        return $lit;
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
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, self::STEP_RANGE_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
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
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, self::STEP_RANGE_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
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
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, self::STEP_RANGE_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
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
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, self::STEP_RANGE_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($ok);
    }

}
