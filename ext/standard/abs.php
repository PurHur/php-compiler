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
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            $i = $var->toInt();
            $frame->returnVar->int($i < 0 ? -$i : $i);
        } elseif (Variable::TYPE_FLOAT === $var->type) {
            $f = $var->toFloat();
            $frame->returnVar->float($f < 0.0 ? -$f : $f);
        } else {
            throw new \LogicException('Unsupported type for abs(): '.$var->type);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('Expecting exactly one argument to abs()');
        }
        $v = $context->helper->loadValue($args[0]);
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                $v = JitLongArg::lower($context, $args[0], 'abs() argument #1');
                $zero = $v->typeOf()->constInt(0, false);
                $isNeg = $context->builder->icmp(Builder::INT_SLT, $v, $zero);
                $negated = $context->builder->negate($v);

                return $context->builder->select($isNeg, $negated, $v);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                $zero = $v->typeOf()->constReal(0.0);
                $isNeg = $context->builder->fcmp(Builder::REAL_OLT, $v, $zero);
                $negated = $context->builder->fNegate($v);

                return $context->builder->select($isNeg, $negated, $v);
        }
        throw new \LogicException('Unsupported type for abs(): '.$args[0]->type);
    }
}
