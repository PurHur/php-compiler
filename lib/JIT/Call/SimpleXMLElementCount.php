<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlCount;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::count() / Iterator leftover of hasChildren (#26863, #35827, #35844). */
final class SimpleXMLElementCount implements Call
{
    public function __construct(private string $name = 'count')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ('count' !== $this->name) {
            return JitSimpleXmlCount::invokeNamed($context, $this->name, ...$args);
        }

        return JitSimpleXmlCount::invoke($context, ...$args);
    }
}
