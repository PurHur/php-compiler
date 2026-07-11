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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * range() — int/float/char bounds (php-src ext/standard/array.c; #4258 VM, JIT int-only).
 */
final class range extends Internal
{
    private const ZERO_STEP_ERROR = 'range(): Argument #3 ($step) must not exceed the specified range';

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

        return RangeIntRuntime::intRange($context, $start, $end, $step);
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
        TypeErrorRaise::emitValueError($context, self::ZERO_STEP_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($ok);
    }

}
