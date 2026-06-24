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
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * ucfirst() for strings (subset of PHP; ASCII letters only in JIT).
 */
final class ucfirst extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('ucfirst() requires exactly one argument');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'ucfirst', 'string', 0);
        $subject = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'ucfirst',
            0,
            'string'
        );
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::asciiUcfirst($subject))
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('ucfirst() requires exactly one argument');
        }
        JitInternalStrictArg::rejectNullString($context, $args[0], 'ucfirst', 'string', 1);
        $str = JitStringBuiltinArg::lower($context, $args[0], 'ucfirst', 0, 'string');
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        lcfirst::transformFirstAscii($context, $copy, ord('a'), ord('z'), -32);

        return $copy;
    }
}
