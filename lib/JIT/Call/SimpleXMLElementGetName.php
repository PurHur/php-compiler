<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlGetName;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::getName() — user-script AOT (#27535). */
final class SimpleXMLElementGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlGetName::invoke($context, ...$args);
    }
}
