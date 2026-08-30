<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::offsetGet() — user-script AOT (#26863). */
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

    public static function invokeUnset(Context $context, JITVariable ...$args): Value
    {
        $us = JitSimpleXmlUserScript::tryOffsetUnset($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::offsetUnset() user-script AOT requires a compile-time offset (#35817 leftover of #35810)'
        );
    }

    public static function invokeSet(Context $context, JITVariable ...$args): Value
    {
        $us = JitSimpleXmlUserScript::tryOffsetSet($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::offsetSet() user-script AOT requires compile-time offset and value (#35810 leftover)'
        );
    }

    public static function invokeExists(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('SimpleXMLElement::offsetExists() expects receiver and offset');
        }
        $us = JitSimpleXmlUserScript::tryFoldDimIsset($context, $args[0], $args[1]);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::offsetExists() user-script AOT requires a compile-time offset (#35810 leftover)'
        );
    }
}
