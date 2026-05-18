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
 * nl2br() for strings (subset of PHP; VM only).
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

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('nl2br() is not implemented for JIT in this compiler build');
    }
}
