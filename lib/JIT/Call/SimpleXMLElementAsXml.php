<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlAsXml;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::asXML() — user-script AOT (#19306). */
final class SimpleXMLElementAsXml implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlAsXml::invoke($context, ...$args);
    }
}
