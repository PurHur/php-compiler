<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlXpath;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::xpath() — user-script AOT (#22720). */
final class SimpleXMLElementXpath implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlXpath::invoke($context, ...$args);
    }
}
