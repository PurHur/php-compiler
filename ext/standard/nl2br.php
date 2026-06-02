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
use PHPCompiler\JIT\Builtin\StringNl2brRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * nl2br() for strings (subset of PHP; JIT/AOT via __compiler_nl2br C runtime).
 */
final class nl2br extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('nl2br() requires one or two arguments');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('nl2br() only supports strings in this compiler build');
        }
        $useXhtml = true;
        if (2 === $argc) {
            $flag = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $flag->type) {
                throw new \LogicException('nl2br() second argument must be a boolean in this compiler build');
            }
            $useXhtml = $flag->toBool();
        }
        $frame->returnVar->string(VmString::nl2br($v->toString(), $useXhtml));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('nl2br() requires one or two arguments');
        }

        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal && 1 === $argc) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::nl2br($literal, true))
            );
        }

        $str = JitStringArg::lower($context, $args[0], 'nl2br() argument #1');
        $i8 = $context->getTypeFromString('int8');
        $useXhtmlI8 = $i8->constInt(1, false);
        if (2 === $argc) {
            $flagVar = $args[1];
            if (JITVariable::TYPE_NATIVE_BOOL !== $flagVar->type) {
                throw new \LogicException('nl2br() second argument must be a boolean in this compiler build');
            }
            $bv = $context->helper->loadValue($flagVar);
            $useXhtmlI8 = $context->builder->zExt($bv, $i8);
        }

        StringNl2brRuntime::ensureLinked($context);

        return JitNl2br::nl2br($context, $str, $useXhtmlI8);
    }

}
