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
use PHPLLVM\Value;

/**
 * hypot() for two integer or float arguments (subset of PHP standard library).
 */
final class hypot extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'hypot', 2);
        $x = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'hypot',
            1,
            'x'
        );
        $y = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'hypot',
            2,
            'y'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\hypot($x, $y));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'hypot', 2)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        [$x, $y] = JitFdiv::lowerOperands($context, $args[0], $args[1], 'hypot', 'x', 'y', 'float');
        $fn = $context->lookupFunction('hypot');

        return $context->builder->call($fn, $x, $y);
    }


}
