<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal IEEE nextafter bitcast kernel for NextafterJitHelper (#19259, #27496).
 * No libc nextafter(3) — LLVM bit walk matches {@see VmMath::nextafter}.
 */
final class phpc_nextafter_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_nextafter_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \LogicException('phpc_nextafter_kernel() expects exactly 2 arguments, '.$argc.' given');
        }
        $num = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'phpc_nextafter_kernel',
            1,
            'num'
        );
        $toward = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'phpc_nextafter_kernel',
            2,
            'next'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->float(VmMath::nextafter($num, $toward));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_nextafter_kernel() expects exactly 2 arguments');
        }
        [$num, $toward] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            'phpc_nextafter_kernel',
            'num',
            'next'
        );

        return JitNextafterKernel::invoke($context, $num, $toward);
    }
}
