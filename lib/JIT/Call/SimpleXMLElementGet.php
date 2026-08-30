<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlGet;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::__get() / __set() — user-script AOT (#26863, #35824 leftover of #35814). */
final class SimpleXMLElementGet implements Call
{
    public function __construct(private string $method = '__get')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ('__set' === $this->method) {
            return JitSimpleXmlGet::invokeSet($context, ...$args);
        }

        return JitSimpleXmlGet::invoke($context, ...$args);
    }
}
