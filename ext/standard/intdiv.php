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

final class intdiv extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('intdiv() requires exactly two arguments');
        }
        $a = $frame->calledArgs[0]->resolveIndirect();
        $b = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $a->type || Variable::TYPE_INTEGER !== $b->type) {
            throw new \LogicException('intdiv() only supports integers in this compiler build');
        }
        $den = $b->toInt();
        if (0 === $den) {
            throw new \DivisionByZeroError('intdiv() division by zero');
        }
        $frame->returnVar->int(\intdiv($a->toInt(), $den));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('intdiv() requires exactly two arguments');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[0]->type || JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('intdiv() only supports integers in this compiler build');
        }
        $l = $context->helper->loadValue($args[0]);
        $r = $context->helper->loadValue($args[1]);

        return $context->builder->signedDiv($l, $r);
    }
}
