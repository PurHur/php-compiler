<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitCreateLazyProxy;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ReflectionClass::createLazyProxy() — MCJIT (#6885). */
final class ReflectionClassCreateLazyProxy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitCreateLazyProxy::invoke($context, ...$args);
    }
}
