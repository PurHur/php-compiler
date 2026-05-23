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

/**
 * is_nan() for float arguments; integers are never NaN (subset of PHP standard library).
 */
final class is_nan extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('is_nan() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            $frame->returnVar->bool(false);

            return;
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            $frame->returnVar->bool(\is_nan($v->toFloat()));

            return;
        }
        throw new \LogicException('is_nan() only supports integers and floats in this compiler build');
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('is_nan() requires exactly one argument');
        }
        if (JITVariable::TYPE_NATIVE_LONG === $args[0]->type) {
            return $context->constantFromBool(false);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE !== $args[0]->type) {
            throw new \LogicException('is_nan() only supports integers and floats in this compiler build');
        }
        $asFloat = JitLongArg::lower($context, $args[0], 'is_nan() argument #1');
        $fn = $context->lookupFunction('isnan');
        $raw = $context->builder->call($fn, $asFloat);
        $zero = $raw->typeOf()->constInt(0, false);

        return $context->builder->icmp(Builder::INT_NE, $raw, $zero);
    }
}
