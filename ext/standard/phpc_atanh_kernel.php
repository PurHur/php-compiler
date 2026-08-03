<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc atanh(3) kernel for AtanhJitHelper (#27058).
 */
final class phpc_atanh_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_atanh_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('phpc_atanh_kernel() expects exactly 1 argument, '.$argc.' given');
        }
        $num = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'phpc_atanh_kernel',
            1,
            'num'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->float(\atanh($num));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_atanh_kernel() expects exactly 1 argument');
        }
        $num = JitFdiv::lowerSingleOperand(
            $context,
            $args[0],
            1,
            'num',
            'phpc_atanh_kernel',
            'float'
        );

        return JitAtanhKernel::invoke($context, $num);
    }
}
