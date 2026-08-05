<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::registerXPathNamespace() — user-script AOT (#27534). */
final class JitSimpleXmlRegisterXPathNamespace
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('SimpleXMLElement::registerXPathNamespace() expects receiver, prefix, and namespace');
        }
        $us = JitSimpleXmlUserScript::tryRegisterXPathNamespace($context, ...$args);
        if (null !== $us) {
            return $us;
        }

        return (new ExternalMethod('simplexmlelement::registerxpathnamespace'))->call($context, ...$args);
    }
}
