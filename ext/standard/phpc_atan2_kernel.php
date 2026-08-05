<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc atan2(3) kernel for Atan2JitHelper (#27017).
 */
final class phpc_atan2_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_atan2_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \LogicException('phpc_atan2_kernel() expects exactly 2 arguments, '.$argc.' given');
        }
        $y = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'phpc_atan2_kernel',
            1,
            'y'
        );
        $x = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'phpc_atan2_kernel',
            2,
            'x'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->float(\atan2($y, $x));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_atan2_kernel() expects exactly 2 arguments');
        }
        [$y, $x] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            'phpc_atan2_kernel',
            'y',
            'x',
            'float',
            true
        );

        return JitAtan2Kernel::invoke($context, $y, $x);
    }
}
