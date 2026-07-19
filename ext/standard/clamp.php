<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * clamp() — PHP 8.6 value bounds (ext/standard/math.c php_math_clamp; RFC clamp_v2, #21022).
 */
final class clamp extends Internal
{
    public function __construct()
    {
        parent::__construct('clamp');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('clamp() expects exactly 3 arguments, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        VmMath::clamp(
            $frame->calledArgs[0],
            $frame->calledArgs[1],
            $frame->calledArgs[2],
            $frame->returnVar
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \ArgumentCountError('clamp() expects exactly 3 arguments, '.\count($args).' given');
        }

        return JitClamp::invoke($context, $args[0], $args[1], $args[2]);
    }
}
