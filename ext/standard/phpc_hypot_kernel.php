<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc hypot(3) kernel for HypotJitHelper (#15074, #20664).
 */
final class phpc_hypot_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_hypot_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \LogicException('phpc_hypot_kernel() expects exactly 2 arguments, '.$argc.' given');
        }
        $x = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'phpc_hypot_kernel',
            1,
            'x'
        );
        $y = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'phpc_hypot_kernel',
            2,
            'y'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->float(\hypot($x, $y));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_hypot_kernel() expects exactly 2 arguments');
        }
        [$x, $y] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            'phpc_hypot_kernel',
            'x',
            'y'
        );

        return JitHypotKernel::invoke($context, $x, $y);
    }
}
