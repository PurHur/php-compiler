<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlGetDocNamespaces;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::getDocNamespaces() — user-script AOT (php-src ext/simplexml/sxe.c). */
final class SimpleXMLElementGetDocNamespaces implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlGetDocNamespaces::invoke($context, ...$args);
    }
}
