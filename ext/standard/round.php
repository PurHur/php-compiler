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
 * round() for integer or float arguments with optional precision and mode (php-src math.c).
 */
final class round extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('round() requires one to three arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $numVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $numVar->type && Variable::TYPE_FLOAT !== $numVar->type) {
            throw new \LogicException('round() only supports integers and floats in this compiler build');
        }

        $precision = 0;
        if ($argc >= 2) {
            $precisionVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $precisionVar->type) {
                throw new \LogicException('round() precision must be an integer in this compiler build');
            }
            $precision = $precisionVar->toInt();
        }

        $mode = StdlibConstants::PHP_ROUND_HALF_UP;
        if (3 === $argc) {
            $modeVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $modeVar->type) {
                throw new \LogicException('round() mode must be an integer in this compiler build');
            }
            $mode = $modeVar->toInt();
        }

        VmRound::apply($frame->returnVar, $numVar, $precision, $mode);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;

        return JitRound::round($context, ...$args);
    }
}
