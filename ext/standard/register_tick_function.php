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
 * register_tick_function() — queue callables for declare(ticks=N) dispatch (#3343).
 */
final class register_tick_function extends Internal
{
    public function __construct()
    {
        parent::__construct('register_tick_function');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'register_tick_function', 1);
        $argc = \count($frame->calledArgs);
        $callable = $frame->calledArgs[0];
        if (EnumCaseSupport::isEnumCaseVariable($callable)) {
            throw new \TypeError('register_tick_function(): Argument #1 ($function) must be a valid callback');
        }
        $resolved = $callable->resolveIndirect();
        if (!VmClosureCall::isClosure($resolved) && !VmCallable::isCallable($frame->vmContext, $callable)) {
            throw new \TypeError('register_tick_function(): Argument #1 ($function) must be a valid callback');
        }
        $extra = [];
        for ($i = 1; $i < $argc; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]->resolveIndirect());
            $extra[] = $copy;
        }
        if (VmClosureCall::isClosure($resolved)) {
            TickQueue::registerClosure(VmClosureCall::resolve($resolved), ...$extra);
            $frame->returnVar->bool(true);

            return;
        }
        $callableCopy = new Variable();
        $callableCopy->copyFrom($resolved);
        TickQueue::register($callableCopy, ...$extra);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('register_tick_function() is not yet supported in JIT mode (#3343)');
    }
}
