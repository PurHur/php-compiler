<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for libxml builtins (php-src ext/libxml/libxml.c; issue #6058).
 */
abstract class LibxmlFunction extends \PHPCompiler\Func\Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not JIT-lowered in this compiler build');
    }
}
