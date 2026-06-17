<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** proc_nice() — process priority (php-src ext/standard/basic_functions.c; #5181). */
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
        $priority = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
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
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitProcNice::invoke($context, $args[0]);
    }
}
