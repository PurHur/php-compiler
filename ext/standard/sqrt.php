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
 * sqrt() for integer or float arguments (subset of PHP standard library).
 */
final class sqrt extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('sqrt() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            $frame->returnVar->float(\sqrt($v->toInt()));

            return;
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            $frame->returnVar->float(\sqrt($v->toFloat()));

            return;
        }
        throw new \LogicException('sqrt() only supports integers and floats in this compiler build');
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('sqrt() requires exactly one argument');
        }
        $double = $context->getTypeFromString('double');
        $v = JitLongArg::lower($context, $args[0], 'sqrt() argument #1');
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                $asFloat = $context->builder->siToFp($v, $double);
                break;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                $asFloat = $v;
                break;
            default:
                throw new \LogicException('sqrt() only supports integers and floats in this compiler build');
        }
        $fn = $context->lookupFunction('sqrt');

        return $context->builder->call($fn, $asFloat);
    }
}
