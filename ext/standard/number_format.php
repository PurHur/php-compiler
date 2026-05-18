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
 * number_format() for integers and floats (C-style locale subset; VM only).
 */
final class number_format extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('number_format() requires one to four arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $numVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $numVar->type && Variable::TYPE_FLOAT !== $numVar->type) {
            throw new \LogicException('number_format() number must be an integer or float in this compiler build');
        }
        $num = Variable::TYPE_INTEGER === $numVar->type ? (float) $numVar->toInt() : $numVar->toFloat();
        $decimals = 0;
        if ($argc >= 2) {
            $decVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $decVar->type) {
                throw new \LogicException('number_format() decimals must be an integer in this compiler build');
            }
            $decimals = $decVar->toInt();
        }
        $decimalSeparator = '.';
        if ($argc >= 3) {
            $sepVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $sepVar->type) {
                throw new \LogicException('number_format() decimal separator must be a string in this compiler build');
            }
            $decimalSeparator = $sepVar->toString();
        }
        $thousandsSeparator = ',';
        if (4 === $argc) {
            $thouVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_STRING !== $thouVar->type) {
                throw new \LogicException('number_format() thousands separator must be a string in this compiler build');
            }
            $thousandsSeparator = $thouVar->toString();
        }
        $frame->returnVar->string(\number_format($num, $decimals, $decimalSeparator, $thousandsSeparator));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('number_format() is not implemented for JIT in this compiler build');
    }
}
