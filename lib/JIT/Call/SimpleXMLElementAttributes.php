<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlAttributes;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::attributes() — user-script AOT (#27535). */
final class SimpleXMLElementAttributes implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlAttributes::invoke($context, ...$args);
    }
}
