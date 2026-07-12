<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for xml builtins (php-src ext/xml/xml.c; #3494, #18203).
 */
abstract class XmlFunction extends \PHPCompiler\Func\Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not JIT-lowered in this compiler build');
    }
}
