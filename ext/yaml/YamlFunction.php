<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for yaml builtins (PECL yaml / yaml.c; #6275).
 */
abstract class YamlFunction extends \PHPCompiler\Func\Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not JIT-lowered in this compiler build');
    }
}
