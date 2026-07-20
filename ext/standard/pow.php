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
        if (VmMath::requiresForwardProfileStrictDoubleNull()) {
            VmMath::parseDoubleBuiltinArg($base, 'pow', 1, 'num', $frame);
            VmMath::parseDoubleBuiltinArg($exp, 'pow', 2, 'exponent', $frame);
        }
        if (null === $frame->returnVar) {
            return;
        }
        VmMath::applyPow($frame->returnVar, $base, $exp, $frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('pow() requires exactly two arguments');
        }
        if (VmMath::requiresForwardProfileStrictDoubleNull()) {
            if (JITVariable::TYPE_NULL === $args[0]->type) {
                JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'pow', 'float');
            }
            if (JITVariable::TYPE_NULL === $args[1]->type) {
                JitFdiv::lowerSingleOperand($context, $args[1], 2, 'exponent', 'pow', 'float');
            }
        }

        return JitPow::invoke($context, ...$args);
    }

    public static function toJitDouble(Context $context, JITVariable $arg, $double): Value
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        $v = JitLongArg::lower($context, $arg, 'pow() argument');

        return $context->builder->siToFp($v, $double);
    }
}
