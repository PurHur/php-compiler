<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::offsetGet() / offsetUnset() — user-script AOT (#26863, #35815). */
final class JitSimpleXmlOffsetGet
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $us = JitSimpleXmlUserScript::tryOffsetGet($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::offsetGet() user-script AOT requires a compile-time offset (#26863)'
        );
    }

    public static function invokeOffsetUnset(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('SimpleXMLElement::offsetUnset() expects receiver and offset');
        }
        $us = JitSimpleXmlUserScript::tryOffsetUnset($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::offsetUnset() user-script AOT requires a compile-time offset (#35815)'
        );
    }
}
