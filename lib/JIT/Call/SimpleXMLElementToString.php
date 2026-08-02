<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlToString;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::__toString() — user-script AOT (#26863). */
final class SimpleXMLElementToString implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlToString::invoke($context, ...$args);
    }
}
