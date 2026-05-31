<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmExceptionHandler;

/**
 * Stack of user exception handlers (set_exception_handler parity, issue #3146).
 *
 * @see Zend/zend_exceptions.c — uncaught handler chain
 */
final class ExceptionHandlerStack
{
    /** @var list<Variable> */
    private array $stack = [];

    public function push(Variable $callback): ?Variable
    {
        $previous = $this->activeCopy();
        $stored = new Variable();
        $stored->copyFrom($callback->resolveIndirect());
        $this->stack[] = $stored;

        return $previous;
    }

    public function pop(): bool
    {
        if ([] === $this->stack) {
            return false;
        }
        array_pop($this->stack);

        return true;
    }

    public function popReturningRemoved(): ?Variable
    {
        if ([] === $this->stack) {
            return null;
        }
        $removed = array_pop($this->stack);
        $out = new Variable();
        $out->copyFrom($removed);

        return $out;
    }

    /**
     * Invoke handlers from innermost to outermost until one handles the exception.
     */
    public function dispatch(Context $context, Variable $exception): bool
    {
        while ([] !== $this->stack) {
            $index = \count($this->stack) - 1;
            $handler = $this->stack[$index];
            if (VmExceptionHandler::invoke($context, $handler, $exception)) {
                return true;
            }
            array_pop($this->stack);
        }

        return false;
    }

    private function activeCopy(): ?Variable
    {
        if ([] === $this->stack) {
            return null;
        }
        $out = new Variable();
        $out->copyFrom($this->stack[\count($this->stack) - 1]);

        return $out;
    }
}
