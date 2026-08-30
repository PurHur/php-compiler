<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlGetNamespaces;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::getNamespaces() — user-script AOT (php-src ext/simplexml/sxe.c). */
final class SimpleXMLElementGetNamespaces implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlGetNamespaces::invoke($context, ...$args);
    }
}
