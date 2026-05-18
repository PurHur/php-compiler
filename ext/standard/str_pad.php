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
 * str_pad() for strings (STR_PAD_RIGHT and STR_PAD_LEFT only).
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
        // Compiler convention: 0 = STR_PAD_LEFT, 1 = STR_PAD_RIGHT (default).
        $padType = 1;
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
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('str_pad() requires two to four arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('str_pad() input must be a string in this compiler build');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('str_pad() pad length must be an integer in this compiler build');
        }
        $input = $context->helper->loadValue($args[0]);
        $padLength = $context->helper->loadValue($args[1]);
        if ($argc >= 3) {
            if (JITVariable::TYPE_STRING !== $args[2]->type) {
                throw new \LogicException('str_pad() pad string must be a string in this compiler build');
            }
            $padString = $context->helper->loadValue($args[2]);
        } else {
            $padString = $context->builder->load($context->constantStringFromString(' '));
        }
        if (4 === $argc) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[3]->type) {
                throw new \LogicException('str_pad() pad type must be an integer in this compiler build');
            }
            $padType = $context->helper->loadValue($args[3]);
        } else {
            $padType = $context->getTypeFromString('int64')->constInt(1, false);
        }

        return JitStrPad::pad($context, $input, $padLength, $padString, $padType);
    }
}
