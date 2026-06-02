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
 * strtoupper() for strings (subset of PHP; ASCII letters only in JIT).
 */
final class strtoupper extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('strtoupper() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::asciiUpper(VmString::coerceOperand($v)));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('strtoupper() requires exactly one argument');
        }
        $str = JitStringArg::lower($context, $args[0], 'strtoupper() string');
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        lcfirst::transformAllAscii($context, $copy, ord('a'), ord('z'), -32);

        return $copy;
    }
}
