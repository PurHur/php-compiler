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
 * str_pad() for strings (STR_PAD_RIGHT and STR_PAD_LEFT only; VM only).
 */
final class str_pad extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('str_pad() requires two to four arguments');
        }
        $input = $frame->calledArgs[0]->resolveIndirect();
        $padLength = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $input->type) {
            throw new \LogicException('str_pad() input must be a string in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $padLength->type) {
            throw new \LogicException('str_pad() pad length must be an integer in this compiler build');
        }
        $padString = ' ';
        if ($argc >= 3) {
            $padArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $padArg->type) {
                throw new \LogicException('str_pad() pad string must be a string in this compiler build');
            }
            $padString = $padArg->toString();
        }
        $padType = 0;
        if (4 === $argc) {
            $typeArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $typeArg->type) {
                throw new \LogicException('str_pad() pad type must be an integer in this compiler build');
            }
            $padType = $typeArg->toInt();
        }
        $frame->returnVar->string(
            VmString::strPad($input->toString(), $padLength->toInt(), $padString, $padType)
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('str_pad() is not implemented for JIT in this compiler build');
    }
}
