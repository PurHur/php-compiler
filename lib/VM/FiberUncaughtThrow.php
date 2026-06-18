<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/** Uncaught Fiber::throw() — propagate injected exception to caller (#9784, Zend/zend_fibers.c). */
final class FiberUncaughtThrow extends \Exception
{
    public function __construct(public readonly Variable $thrown)
    {
        parent::__construct('Fiber uncaught throw');
    }
}
