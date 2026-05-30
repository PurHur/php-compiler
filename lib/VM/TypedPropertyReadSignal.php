<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * VM-internal signal when a typed property is read before initialization (#3429).
 */
final class TypedPropertyReadSignal extends \Exception
{
    public function __construct(public readonly Variable $errorObject)
    {
        parent::__construct('Typed property read');
    }
}
