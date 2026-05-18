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
 * ucfirst() for strings (subset of PHP; ASCII letters only in JIT).
 */
final class ucfirst extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('ucfirst() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('ucfirst() only supports strings in this compiler build');
        }
        $frame->returnVar->string(\ucfirst($v->toString()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('ucfirst() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('ucfirst() only supports strings in this compiler build');
        }
        $str = $context->helper->loadValue($args[0]);
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        lcfirst::transformFirstAscii($context, $copy, ord('a'), ord('z'), -32);

        return $copy;
    }
}
