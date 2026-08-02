<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc fmod(3) kernel for FmodJitHelper (#26994).
 */
final class phpc_fmod_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_fmod_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \LogicException('phpc_fmod_kernel() expects exactly 2 arguments, '.$argc.' given');
        }
        $num1 = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'phpc_fmod_kernel',
            1,
            'num1'
        );
        $num2 = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'phpc_fmod_kernel',
            2,
            'num2'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->float(\fmod($num1, $num2));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_fmod_kernel() expects exactly 2 arguments');
        }
        [$num1, $num2] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            'phpc_fmod_kernel',
            'num1',
            'num2'
        );

        return JitFmodKernel::invoke($context, $num1, $num2);
    }
}
