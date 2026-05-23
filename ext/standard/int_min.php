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
 * min() with exactly two integer or float arguments (subset of PHP standard library).
 */
final class int_min extends Internal
{
    public function __construct()
    {
        parent::__construct('min');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('This min() implementation requires exactly two arguments');
        }
        $a = $frame->calledArgs[0]->resolveIndirect();
        $b = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER === $a->type && Variable::TYPE_INTEGER === $b->type) {
            $ai = $a->toInt();
            $bi = $b->toInt();
            $frame->returnVar->int($ai < $bi ? $ai : $bi);

            return;
        }
        if (self::isNumeric($a) && self::isNumeric($b)) {
            $af = self::toFloat($a);
            $bf = self::toFloat($b);
            $frame->returnVar->float($af < $bf ? $af : $bf);

            return;
        }
        throw new \LogicException('min() only supports two integers or floats in this compiler build');
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('This min() implementation requires exactly two arguments');
        }
        if (JITVariable::TYPE_NATIVE_LONG === $args[0]->type && JITVariable::TYPE_NATIVE_LONG === $args[1]->type) {
            $l = JitLongArg::lower($context, $args[0], 'min() argument #1');
            $r = JitLongArg::lower($context, $args[1], 'min() argument #2');
            $cmp = $context->builder->icmp(Builder::INT_SLT, $l, $r);

            return $context->builder->select($cmp, $l, $r);
        }
        if (self::isJitNumeric($args[0]) && self::isJitNumeric($args[1])) {
            $double = $context->getTypeFromString('double');
            $l = pow::toJitDouble($context, $args[0], $double);
            $r = pow::toJitDouble($context, $args[1], $double);
            $cmp = $context->builder->fcmp(Builder::REAL_OLT, $l, $r);

            return $context->builder->select($cmp, $l, $r);
        }
        throw new \LogicException('min() only supports two integers or floats in this compiler build');
    }

    private static function isNumeric(Variable $v): bool
    {
        return Variable::TYPE_INTEGER === $v->type || Variable::TYPE_FLOAT === $v->type;
    }

    private static function isJitNumeric(JITVariable $v): bool
    {
        return JITVariable::TYPE_NATIVE_LONG === $v->type || JITVariable::TYPE_NATIVE_DOUBLE === $v->type;
    }

    private static function toFloat(Variable $v): float
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return (float) $v->toInt();
        }

        return $v->toFloat();
    }
}
