<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlOffsetGet;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement ArrayAccess — user-script AOT (#26863, leftover of #35810). */
final class SimpleXMLElementOffsetGet implements Call
{
    public function __construct(private string $method = 'offsetGet')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ('offsetUnset' === $this->method) {
            return JitSimpleXmlOffsetGet::invokeUnset($context, ...$args);
        }
        if ('offsetSet' === $this->method) {
            return JitSimpleXmlOffsetGet::invokeSet($context, ...$args);
        }
        if ('offsetExists' === $this->method) {
            return JitSimpleXmlOffsetGet::invokeExists($context, ...$args);
        }

        return JitSimpleXmlOffsetGet::invoke($context, ...$args);
    }
}
