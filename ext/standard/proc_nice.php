<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** proc_nice() — VM via VmProcNicePure; JIT/AOT via ProcNiceJitHelper (#5181, #30615). */
final class proc_nice extends Internal
{
    public function __construct()
    {
        parent::__construct('proc_nice');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'proc_nice() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $priority = VmMath::parseIntBuiltinArgForFrame(
            $frame,
            0,
            'proc_nice',
            1,
            'priority'
        );
        $frame->returnVar->bool(VmProcess::proc_nice($priority));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'proc_nice() expects exactly 1 argument, '.$argc.' given'
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        JitInternalStrictArg::requireInt($context, $args[0], 'proc_nice', 'priority', 1);

        return JitProcNice::invoke(
            $context,
            JitLongArg::lower($context, $args[0], 'proc_nice() priority')
        );
    }
}
