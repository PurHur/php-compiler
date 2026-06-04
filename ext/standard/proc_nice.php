<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
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
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('proc_nice() requires exactly one argument');
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
        if (1 !== \count($args)) {
            throw new \LogicException('proc_nice() requires exactly one argument');
        }

        return JitProcNice::invoke($context, $args[0]);
    }
}
