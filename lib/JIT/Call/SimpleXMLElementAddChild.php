<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlAddChild;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::addChild() — user-script AOT (#19306). */
final class SimpleXMLElementAddChild implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlAddChild::invoke($context, ...$args);
    }
}
