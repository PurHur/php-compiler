<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlGet;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::__get() — user-script AOT (#26863). */
final class SimpleXMLElementGet implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlGet::invoke($context, ...$args);
    }
}
