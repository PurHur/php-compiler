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
use PHPCompiler\JIT\Builtin\StringStrRot13;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_rot13() for strings (subset of PHP; ASCII letters only).
 *
 * VM: {@see VmString::strRot13()}; JIT/AOT: {@see StringStrRot13} + {@see StrRot13JitHelper}.
 */
final class str_rot13 extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('str_rot13() requires exactly one argument');
        }
        $subject = VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[0],
            'str_rot13',
            0,
            'string'
        );
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::strRot13($subject))
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('str_rot13() requires exactly one argument');
        }

        $str = JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'str_rot13', 0, 'string');
        StringStrRot13::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_str_rot13'),
            $str
        );
    }
}
