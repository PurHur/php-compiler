<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\TickQueue;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * unregister_tick_function() — remove tick callback (#3343).
 */
final class unregister_tick_function extends Internal
{
    public function __construct()
    {
        parent::__construct('unregister_tick_function');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'unregister_tick_function', 1);
        $callable = $frame->calledArgs[0];
        if (EnumCaseSupport::isEnumCaseVariable($callable)) {
            throw new \TypeError('unregister_tick_function(): Argument #1 ($function) must be a valid callback');
        }
        $resolved = $callable->resolveIndirect();
        if (!VmClosureCall::isClosure($resolved) && !VmCallable::isCallable($frame->vmContext, $callable)) {
            throw new \TypeError('unregister_tick_function(): Argument #1 ($function) must be a valid callback');
        }
        $callableCopy = new Variable();
        $callableCopy->copyFrom($resolved);
        TickQueue::unregister($callableCopy);
        $frame->returnVar->null();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('unregister_tick_function() is not yet supported in JIT mode (#3343)');
    }
}
