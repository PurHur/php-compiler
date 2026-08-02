<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlOffsetGet;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::offsetGet() — user-script AOT (#26863). */
final class SimpleXMLElementOffsetGet implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlOffsetGet::invoke($context, ...$args);
    }
}
