<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\ext\standard\VmExceptionHandler;

/**
 * Stack of user exception handlers (set_exception_handler parity, issue #3146).
 *
 * @see Zend/zend_exceptions.c — uncaught handler chain
 */
final class ExceptionHandlerStack
{
    /** @var list<array{0: Variable, 1: ?ClosureState}> */
    private array $stack = [];

    public function push(Variable $callback): ?Variable
    {
        $previous = $this->activeCopy();
        $resolved = $callback->resolveIndirect();
        $closureState = VmClosureCall::isClosure($resolved) ? VmClosureCall::resolve($resolved) : null;
        $stored = new Variable();
        $stored->copyFrom($resolved);
        if (null !== $closureState) {
            ObjectLifetime::addRef($resolved->toObject());
        }
        $this->stack[] = [$stored, $closureState];

        return $previous;
    }

    public function pop(): bool
    {
        if ([] === $this->stack) {
            return true;
        }
        array_pop($this->stack);

        return true;
    }

    public function popReturningRemoved(): ?Variable
    {
        if ([] === $this->stack) {
            return null;
        }
        [$removed] = array_pop($this->stack);
        $out = new Variable();
        $out->copyFrom($removed);

        return $out;
    }

    /**
     * Invoke handlers from innermost to outermost until one handles the exception.
     *
     * Handlers that return false are not removed from the stack (Zend parity).
     */
    public function dispatch(Context $context, Variable $exception): bool
    {
        for ($i = \count($this->stack) - 1; $i >= 0; $i--) {
            [$handler, $closureState] = $this->stack[$i];
            if (VmExceptionHandler::invoke($context, $handler, $exception, $closureState)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(Variable): void $visitVar
     */
    public function visitGcRoots(callable $visitVar): void
    {
        foreach ($this->stack as [$handler, $closureState]) {
            $visitVar($handler);
            if (null !== $closureState) {
                foreach ($closureState->captures as $capture) {
                    $visitVar($capture['var']);
                }
                foreach ($closureState->staticRootsForCycleCollector() as $static) {
                    $visitVar($static);
                }
            }
        }
    }

    private function activeCopy(): ?Variable
    {
        if ([] === $this->stack) {
            return null;
        }
        $out = new Variable();
        $out->copyFrom($this->stack[\count($this->stack) - 1][0]);

        return $out;
    }
}
