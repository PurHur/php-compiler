<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlOffsetGet;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::offsetGet() / offsetUnset() — user-script AOT (#26863, #35815). */
final class SimpleXMLElementOffsetGet implements Call
{
    public function __construct(private string $name = 'offsetGet')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ('offsetUnset' === $this->name) {
            return JitSimpleXmlOffsetGet::invokeOffsetUnset($context, ...$args);
        }

        return JitSimpleXmlOffsetGet::invoke($context, ...$args);
    }
}
