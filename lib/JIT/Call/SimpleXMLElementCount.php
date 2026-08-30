<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlCount;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::count() / hasChildren() — user-script AOT (#26863, #35827 leftover). */
final class SimpleXMLElementCount implements Call
{
    public function __construct(private string $name = 'count')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ('hasChildren' === $this->name) {
            return JitSimpleXmlCount::invokeHasChildren($context, ...$args);
        }

        return JitSimpleXmlCount::invoke($context, ...$args);
    }
}
