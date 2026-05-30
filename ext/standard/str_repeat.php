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
 * str_repeat() for strings (subset of PHP; native LLVM in JIT).
 */
final class str_repeat extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('str_repeat() requires exactly two arguments');
        }
        $input = $frame->calledArgs[0]->resolveIndirect();
        $mult = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $input->type) {
            throw new \LogicException('str_repeat() input must be a string in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $mult->type) {
            throw new \LogicException('str_repeat() multiplier must be an integer in this compiler build');
        }
        $result = VmString::repeat($input->toString(), $mult->toInt());
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string($result);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('str_repeat() requires exactly two arguments');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('str_repeat() multiplier must be an integer in this compiler build');
        }
        $multiplier = $context->helper->loadValue($args[1]);
        JitStrRepeat::emitRuntimeTimesGuard($context, $multiplier);

        return JitStrRepeat::repeat(
            $context,
            $this->jitString($context, $args[0], 'str_repeat() argument #1'),
            $multiplier
        );
    }
}
