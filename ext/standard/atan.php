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
 * atan() for integer or float arguments (ext/standard/math.c parity).
 */
final class atan extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('atan() requires exactly one argument');
        }
        $num = VmMath::parseStrictFloatBuiltinArgForFrame(
            $frame,
            'atan',
            1,
            'num'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\atan($num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('atan() requires exactly one argument');
        }
        $asFloat = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'atan', 'float');
        $fn = $context->lookupFunction('atan');

        return $context->builder->call($fn, $asFloat);
    }

}
