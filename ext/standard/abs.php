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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class abs extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('Expecting exactly one argument to abs()');
        }
        $num = VmMath::parseNumberBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'abs',
            1,
            'num'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (\is_int($num)) {
            $frame->returnVar->int($num < 0 ? -$num : $num);

            return;
        }
        $frame->returnVar->float($num < 0.0 ? -$num : $num);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('Expecting exactly one argument to abs()');
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $args[0]->type) {
            $v = $context->helper->loadValue($args[0]);
            $zero = $v->typeOf()->constReal(0.0);
            $isNeg = $context->builder->fcmp(Builder::REAL_OLT, $v, $zero);
            $negated = $context->builder->fNegate($v);

            return $context->builder->select($isNeg, $negated, $v);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $args[0]->type) {
            $v = JitLongArg::lower($context, $args[0], 'abs() argument #1');
            $zero = $v->typeOf()->constInt(0, false);
            $isNeg = $context->builder->icmp(Builder::INT_SLT, $v, $zero);
            $negated = $context->builder->negate($v);

            return $context->builder->select($isNeg, $negated, $v);
        }
        $asFloat = JitMathNumberArg::lowerToDouble($context, $args[0], 'abs', 1, 'num');
        $zero = $asFloat->typeOf()->constReal(0.0);
        $isNeg = $context->builder->fcmp(Builder::REAL_OLT, $asFloat, $zero);
        $negated = $context->builder->fNegate($asFloat);

        return $context->builder->select($isNeg, $negated, $asFloat);
    }
}
