<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Fiber::isRunning(): bool — JIT/AOT (#26801, Zend/zend_fibers.c). */
final class FiberIsRunning implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Fiber::isRunning() called without $this');
        }

        return FiberHelper::loadStatusBool($context, $args[0], 'running')->value;
    }
}
