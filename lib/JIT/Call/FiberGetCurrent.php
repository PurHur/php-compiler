<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Fiber::getCurrent(): ?Fiber — JIT/AOT (#30906, Zend/zend_fibers.c).
 *
 * 0-arg still returns null (prior ExternalMethod stub). Excess argc is ArgumentCountError.
 */
final class FiberGetCurrent implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src Zend/zend_fibers.stub.php — static getCurrent(): ?Fiber; ZEND_PARSE_PARAMETERS_NONE
        if (!FiberHelper::emitExactStaticArgc($context, $args, 'Fiber::getCurrent', 0)) {
            return FiberHelper::dummyNullValue($context);
        }

        return FiberHelper::dummyNullValue($context);
    }
}
