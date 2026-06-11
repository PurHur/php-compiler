<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitCreateLazyGhost;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ReflectionClass::createLazyGhost() — MCJIT (#6885). */
final class ReflectionClassCreateLazyGhost implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitCreateLazyGhost::invoke($context, ...$args);
    }
}
