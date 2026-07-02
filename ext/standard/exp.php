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
use PHPCompiler\JIT\Builtin\MathExp;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * exp() for integer or float arguments (subset of PHP standard library).
 */
final class exp extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('exp() requires exactly one argument');
        }
        $num = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'exp',
            1,
            'num'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::exp($num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('exp() requires exactly one argument');
        }
        $double = $context->getTypeFromString('double');
        $asFloat = pow::toJitDouble($context, $args[0], $double);
        if (JITVariable::TYPE_NATIVE_LONG === $args[0]->type) {
            JitLongArg::lower($context, $args[0], 'exp() argument #1');
        }
        if (isset($args[1]) && JITVariable::TYPE_NATIVE_LONG === $args[1]->type) {
            JitLongArg::lower($context, $args[1], 'exp() argument #2');
        }

        return MathExp::invoke($context, $asFloat);
    }

}
