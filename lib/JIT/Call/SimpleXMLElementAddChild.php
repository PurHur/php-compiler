<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlAddChild;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::addChild() / addAttribute() — user-script AOT (#19306, #35806). */
final class SimpleXMLElementAddChild implements Call
{
    public function __construct(private string $name = 'addChild')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ('addAttribute' === $this->name) {
            return JitSimpleXmlAddChild::invokeAddAttribute($context, ...$args);
        }

        return JitSimpleXmlAddChild::invoke($context, ...$args);
    }
}
