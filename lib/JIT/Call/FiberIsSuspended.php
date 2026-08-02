<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Fiber::isSuspended(): bool — JIT/AOT (#26801, Zend/zend_fibers.c). */
final class FiberIsSuspended implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Fiber::isSuspended() called without $this');
        }

        return FiberHelper::loadStatusBool($context, $args[0], 'suspended')->value;
    }
}
