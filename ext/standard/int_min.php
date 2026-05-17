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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * min() with exactly two integer arguments (subset of PHP standard library).
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
        if (Variable::TYPE_INTEGER !== $a->type || Variable::TYPE_INTEGER !== $b->type) {
            throw new \LogicException('min() only supports two integers in this compiler build');
        }
        $frame->returnVar->int(\min($a->toInt(), $b->toInt()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('This min() implementation requires exactly two arguments');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[0]->type || JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('min() only supports two integers in this compiler build');
        }
        $l = $context->helper->loadValue($args[0]);
        $r = $context->helper->loadValue($args[1]);
        $cmp = $context->builder->icmp(Builder::INT_SLT, $l, $r);

        return $context->builder->select($cmp, $l, $r);
    }
}
