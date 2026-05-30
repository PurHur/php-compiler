<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/** Uncaught Generator::throw() — propagate injected exception to caller (#167). */
final class GeneratorUncaughtThrow extends \Exception
{
    public function __construct(public readonly Variable $thrown)
    {
        parent::__construct('Generator uncaught throw');
    }
}
