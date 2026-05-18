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
 * htmlspecialchars() for strings (subset of PHP; JIT supports default ENT_QUOTES).
 */
final class htmlspecialchars extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('htmlspecialchars() requires one to four arguments');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('htmlspecialchars() only supports strings in this compiler build');
        }
        $string = $v->toString();
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        $encoding = 'UTF-8';
        $doubleEncode = true;
        if ($argc >= 2) {
            $flagsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('htmlspecialchars() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        if ($argc >= 3) {
            $encVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $encVar->type) {
                throw new \LogicException('htmlspecialchars() encoding must be a string in this compiler build');
            }
            $encoding = $encVar->toString();
        }
        if (4 === $argc) {
            $deVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $deVar->type) {
                throw new \LogicException('htmlspecialchars() double_encode must be a boolean in this compiler build');
            }
            $doubleEncode = $deVar->toBool();
        }
        $frame->returnVar->string(VmString::htmlspecialchars($string, $flags, $encoding, $doubleEncode));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('htmlspecialchars() requires one to four arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('htmlspecialchars() only supports strings in this compiler build');
        }
        if ($argc >= 2) {
            throw new \LogicException(
                'htmlspecialchars() JIT only supports the default flags (ENT_QUOTES) in this compiler build'
            );
        }

        $str = $context->helper->loadValue($args[0]);

        return JitHtmlspecialchars::escape($context, $str);
    }
}
