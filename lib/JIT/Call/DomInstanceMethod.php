<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ext/dom instance methods — JIT/AOT via VmDomInstanceInvoke (#17130). */
final class DomInstanceMethod implements Call
{
    public function __construct(
        private readonly string $classLc,
        private readonly string $methodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException($this->classLc.'::'.$this->methodLc.'() called without $this');
        }
        $extra = \array_slice($args, 1);

        return DomInstanceMethodRuntime::invoke(
            $context,
            \count($extra),
            $this->methodLc,
            $args[0],
            ...$extra
        );
    }
}
