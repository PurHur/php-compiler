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
        // php-src ext/standard/math.c — ArgumentCountError (#21982).
        $this->requireExactArgCount($frame, 'pow', 2);
        $base = $frame->calledArgs[0]->resolveIndirect();
        $exp = $frame->calledArgs[1]->resolveIndirect();
        // pow() uses the ** / zend_operators path — null coerces silently (no Z_PARAM_DOUBLE DEP).
        // Contrast fpow()/sqrt() which emit soft-null E_DEPRECATED (#29322, re-#20951).
        if (null === $frame->returnVar) {
            return;
        }
        VmMath::applyPow($frame->returnVar, $base, $exp, $frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'pow', 2)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        // No float-null DEP here — match operator-path silence (#29322). fpow keeps soft-null.

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
