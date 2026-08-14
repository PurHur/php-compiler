<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Fiber::isStarted(): bool — JIT/AOT (#26801, Zend/zend_fibers.c). */
final class FiberIsStarted implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Fiber::isStarted() called without $this');
        }
        // php-src Zend/zend_fibers.stub.php — isStarted(): bool; ZEND_PARSE_PARAMETERS_NONE (#30906)
        if (!FiberHelper::emitExactInstanceUserArgc($context, $args, 'Fiber::isStarted', 0)) {
            return FiberHelper::dummyNativeFalse($context);
        }

        return FiberHelper::loadStatusBool($context, $args[0], 'started')->value;
    }
}
