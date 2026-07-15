<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc pow(3) kernel for FpowJitHelper (#19259).
 */
final class phpc_fpow_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_fpow_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \LogicException('phpc_fpow_kernel() expects exactly 2 arguments, '.$argc.' given');
        }
        $num = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'phpc_fpow_kernel',
            1,
            'num'
        );
        $exponent = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'phpc_fpow_kernel',
            2,
            'exponent'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->float(\pow($num, $exponent));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_fpow_kernel() expects exactly 2 arguments');
        }
        [$num, $exponent] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            'phpc_fpow_kernel',
            'num',
            'exponent'
        );

        return JitFpowKernel::invoke($context, $num, $exponent);
    }
}
