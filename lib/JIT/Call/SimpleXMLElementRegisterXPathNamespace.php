<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlRegisterXPathNamespace;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::registerXPathNamespace() — user-script AOT (#27534). */
final class SimpleXMLElementRegisterXPathNamespace implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlRegisterXPathNamespace::invoke($context, ...$args);
    }
}
