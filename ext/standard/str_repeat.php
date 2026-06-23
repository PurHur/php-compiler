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
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $input = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'str_repeat',
            0,
            'string'
        );
        $times = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'str_repeat', 2, 'times');
        $result = VmString::repeat($input, $times);
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
        $multiplier = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'str_repeat', 2, 'times', true);
        JitStrRepeat::emitRuntimeTimesGuard($context, $multiplier);

        return JitStrRepeat::repeat(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'str_repeat', 0, 'string'),
            $multiplier
        );
    }
}
