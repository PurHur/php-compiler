<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlCount;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::count() — user-script AOT (#26863). */
final class SimpleXMLElementCount implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlCount::invoke($context, ...$args);
    }
}
