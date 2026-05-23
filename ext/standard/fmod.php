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
use PHPLLVM\Value;

/**
 * fmod() with two integer or float arguments (subset of PHP standard library).
 */
final class fmod extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('fmod() requires exactly two arguments');
        }
        $a = $frame->calledArgs[0]->resolveIndirect();
        $b = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\fmod(self::toFloat($a), self::toFloat($b)));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('fmod() requires exactly two arguments');
        }
        $double = $context->getTypeFromString('double');
        $left = pow::toJitDouble($context, $args[0], $double);
        $right = pow::toJitDouble($context, $args[1], $double);
        $fn = $context->lookupFunction('fmod');

        if (JITVariable::TYPE_NATIVE_LONG === $args[0]->type) {
            JitLongArg::lower($context, $args[0], 'fmod() argument #1');
        }
        if (isset($args[1]) && JITVariable::TYPE_NATIVE_LONG === $args[1]->type) {
            JitLongArg::lower($context, $args[1], 'fmod() argument #2');
        }

        return $context->builder->call($fn, $left, $right);
    }

    private static function toFloat(Variable $v): float
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return (float) $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return $v->toFloat();
        }
        throw new \LogicException('fmod() only supports integers and floats in this compiler build');
    }
}
