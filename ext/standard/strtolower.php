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
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * strtolower() for strings (subset of PHP; ASCII letters only in JIT).
 */
final class strtolower extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('strtolower() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('strtolower() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::asciiLower($v->toString()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('strtolower() requires exactly one argument');
        }
        $str = JitStringArg::lower($context, $args[0], 'strtolower() string');
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        lcfirst::transformAllAscii($context, $copy, ord('A'), ord('Z'), 32);

        return $copy;
    }
}
