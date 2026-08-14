<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\FiberTrace;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * ReflectionFiber::getTrace(int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT): array — VM (#4609, #30928).
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionFiber_getTrace
 */
final class ReflectionFiberGetTrace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTrace');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub: getTrace(int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT): array
        $this->requireUserArgCountRange($frame, 'ReflectionFiber::getTrace', 0, 1);
        $receiver = ReflectionSupport::requireReflectionFiber($frame, $frame->calledArgs[0]);
        $fiber = FiberTrace::fiberStateFromReflection($receiver);
        FiberTrace::requireSuspended($fiber, 'ReflectionFiber::getTrace()');
        if (\count($frame->calledArgs) >= 2) {
            $optionsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $optionsVar->type) {
                throw new \TypeError(sprintf(
                    'ReflectionFiber::getTrace(): Argument #1 ($options) must be of type int, %s given',
                    EnumCaseSupport::typeNameForVariable($optionsVar)
                ));
            }
            // Options currently unused: suspendedTrace is captured at suspend time (#4609).
        }
        if (null === $frame->returnVar) {
            return;
        }
        $trace = $fiber->suspendedTrace->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $trace->type) {
            $frame->returnVar->newArray();

            return;
        }
        $frame->returnVar->copyFrom($trace);
    }
}
