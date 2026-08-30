<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlAddAttribute;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::addAttribute() — user-script AOT (#35806). */
final class SimpleXMLElementAddAttribute implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlAddAttribute::invoke($context, ...$args);
    }
}
