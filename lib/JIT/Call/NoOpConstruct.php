<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * No-op `__construct` for dynamic `new $class` when the runtime class has no constructor (#27156).
 *
 * Used as a RuntimeIndirectInstanceMethodCall candidate so stdClass / ctor-less classes
 * do not hit the undef→abort path when other classes in the module declare __construct.
 */
final class NoOpConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // Void ctor result — same shape as ExceptionConstruct (#27156).
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
