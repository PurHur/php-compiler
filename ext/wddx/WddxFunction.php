<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for wddx builtins (php-src ext/wddx/wddx.c; #6327).
 */
abstract class WddxFunction extends \PHPCompiler\Func\Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not JIT-lowered in this compiler build');
    }
}
