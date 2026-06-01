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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pow() with two integer or float arguments (subset of PHP standard library).
 */
final class pow extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('pow() requires exactly two arguments');
        }
        $base = $frame->calledArgs[0]->resolveIndirect();
        $exp = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        VmMath::applyPow($frame->returnVar, $base, $exp);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitPow::invoke($context, ...$args);
    }

    public static function toJitDouble(Context $context, JITVariable $arg, $double): Value
    {
        $v = JitLongArg::lower($context, $arg, 'pow() argument');
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $context->builder->siToFp($v, $double);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $v;
            default:
                throw new \LogicException('pow() only supports integers and floats in this compiler build');
        }
    }
}
