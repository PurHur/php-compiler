<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlCount;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::count() / Iterator leftover of hasChildren — user-script AOT (#26863, #35827, #35844). */
final class SimpleXMLElementCount implements Call
{
    public function __construct(private string $name = 'count')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match ($this->name) {
            'hasChildren' => JitSimpleXmlCount::invokeHasChildren($context, ...$args),
            'rewind' => JitSimpleXmlCount::invokeRewind($context, ...$args),
            'valid' => JitSimpleXmlCount::invokeValid($context, ...$args),
            'current' => JitSimpleXmlCount::invokeCurrent($context, ...$args),
            'key' => JitSimpleXmlCount::invokeKey($context, ...$args),
            'next' => JitSimpleXmlCount::invokeNext($context, ...$args),
            'getChildren' => JitSimpleXmlCount::invokeGetChildren($context, ...$args),
            default => JitSimpleXmlCount::invoke($context, ...$args),
        };
    }
}
