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
use PHPLLVM\Value;

/**
 * intval() for integer or float arguments (truncates toward zero; subset of PHP).
 */
final class intval extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('intval() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            $frame->returnVar->int($v->toInt());

            return;
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            $frame->returnVar->int((int) $v->toFloat());

            return;
        }
        throw new \LogicException('intval() only supports integers and floats in this compiler build');
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('intval() requires exactly one argument');
        }
        $v = $context->helper->loadValue($args[0]);
        $i64 = $context->getTypeFromString('int64');
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $v;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->builder->fpToSi($v, $i64);
            default:
                throw new \LogicException('intval() only supports integers and floats in this compiler build');
        }
    }
}
