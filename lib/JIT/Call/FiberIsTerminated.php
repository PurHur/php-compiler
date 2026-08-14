<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Fiber::isTerminated(): bool — JIT/AOT (#26801, Zend/zend_fibers.c). */
final class FiberIsTerminated implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Fiber::isTerminated() called without $this');
        }
        // php-src Zend/zend_fibers.stub.php — isTerminated(): bool; ZEND_PARSE_PARAMETERS_NONE (#30906)
        if (!FiberHelper::emitExactInstanceUserArgc($context, $args, 'Fiber::isTerminated', 0)) {
            return FiberHelper::dummyNativeFalse($context);
        }

        return FiberHelper::loadStatusBool($context, $args[0], 'terminated')->value;
    }
}
