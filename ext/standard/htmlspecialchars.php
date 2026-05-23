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
 * htmlspecialchars() for strings (subset of PHP; JIT supports flags for ENT_QUOTES / ENT_COMPAT).
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
        if ($argc >= 3) {
            throw new \LogicException(
                'htmlspecialchars() JIT only supports string and optional flags in this compiler build'
            );
        }

        if ($argc >= 2 && JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('htmlspecialchars() flags must be an integer in this compiler build');
        }

        $literal = $args[0]->compileTimeString ?? null;
        if (null !== $literal && 1 === $argc) {
            return $context->builder->load(
                $context->constantStringFromString(
                    VmString::htmlspecialchars($literal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true)
                )
            );
        }

        $str = JitStringArg::lower($context, $args[0], 'htmlspecialchars() string');
        $flags = $context->getTypeFromString('int64')->constInt(ENT_QUOTES | ENT_SUBSTITUTE, false);
        if ($argc >= 2) {
            $flags = $context->helper->loadValue($args[1]);
        }

        return JitHtmlspecialchars::escape($context, $str, $flags);
    }

}
