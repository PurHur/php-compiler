<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlChildren;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::children() — user-script AOT (#27535). */
final class SimpleXMLElementChildren implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlChildren::invoke($context, ...$args);
    }
}
