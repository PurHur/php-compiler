<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Fiber::suspend() outside fiber resume lowering — compile error (#4019).
 */
final class FiberSuspendStatic implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src Zend/zend_fibers.stub.php — static suspend(mixed $value = null); at most 1 (#30906)
        if (!FiberHelper::emitAtMostStaticArgc($context, $args, 'Fiber::suspend', 1)) {
            return FiberHelper::dummyNullValue($context);
        }
        if ($context->compilingFiberResume) {
            throw new \LogicException('Fiber::suspend() must be lowered in fiber resume function');
        }
        throw new \LogicException('Fiber::suspend() cannot be called from this context in JIT');
    }
}
