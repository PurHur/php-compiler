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
 * str_rot13() for strings (subset of PHP; ASCII letters only in JIT).
 */
final class str_rot13 extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('str_rot13() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('str_rot13() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::strRot13($v->toString()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('str_rot13() requires exactly one argument');
        }

        return JitStrRot13::rot13($context, $args[0]);
    }
}
