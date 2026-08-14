<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ReflectionProperty/Method::setAccessible($accessible) — thin AOT (#30910, #9823).
 *
 * php-src 8.1+: getValue/setValue/invoke ignore the accessible flag (#22090 / #22091).
 * Keep a real Call so thin AOT does not throw undefined method; body is a no-op.
 */
final class ReflectionSetAccessible implements Call
{
    public function __construct(
        private readonly string $reflectionClassName,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \ArgumentCountError(
                $this->reflectionClassName.'::setAccessible() expects exactly 1 argument, '
                .(\count($args) - 1).' given'
            );
        }
        // Discard flag — Zend 8.1+ ignores it for property/method access.
        unset($args);
        $resultSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $resultSlot)
        );

        return $resultSlot;
    }
}
