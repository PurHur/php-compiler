<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Compile-time no-op for instance/static methods on classes not lowered into the bundle (#579).
 *
 * Returns null; does not invoke Zend or vendor code at runtime.
 */
final class ExternalMethod implements Call
{
    public function __construct(
        public readonly string $proxyName,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $context->recordExternalMethodStub($this->proxyName);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
