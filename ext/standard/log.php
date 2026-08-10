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
use PHPCompiler\JIT\Builtin\MathLog;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * log() — natural or arbitrary-base logarithm (php-src ext/standard/math.c).
 *
 * Optional `$base` (#21980); wrong arity → ArgumentCountError (#21964 sibling).
 */
final class log extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src math.c ZEND_PARSE_PARAMETERS_START(1, 2) — ArgumentCountError (#21980).
        $this->requireArgCountRange($frame, 'log', 1, 2);
        $num = VmMath::parseStrictFloatBuiltinArgForFrame(
            $frame,
            'log',
            1,
            'num'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (2 === \count($frame->calledArgs)) {
            $base = VmMath::parseStrictFloatBuiltinArgForFrame(
                $frame,
                'log',
                2,
                'base'
            );
            $frame->returnVar->float(VmMath::logWithBase($num, $base));

            return;
        }
        $frame->returnVar->float(VmMath::log($num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireArgCountRangeJit($context, $args, 'log', 1, 2)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        $asFloat = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'log', 'float');
        if (isset($args[1])) {
            $base = JitFdiv::lowerSingleOperand($context, $args[1], 2, 'base', 'log', 'float');

            return MathLog::invokeWithBase($context, $asFloat, $base);
        }

        return MathLog::invoke($context, $asFloat);
    }
}
