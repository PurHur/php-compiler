<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::__get() / __set() — user-script AOT (#26863, #35820). */
final class JitSimpleXmlGet
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $us = JitSimpleXmlUserScript::tryGet($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::__get() user-script AOT requires a compile-time property name (#26863)'
        );
    }

    public static function invokeSet(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('SimpleXMLElement::__set() expects receiver, name, and value');
        }
        $name = \PHPCompiler\JIT\JitStringBuiltinArg::compileTimeLiteral($args[1])
            ?? $args[1]->compileTimeString;
        if (null === $name) {
            throw new \LogicException(
                'SimpleXMLElement::__set() user-script AOT requires a compile-time property name (#35820)'
            );
        }
        $us = JitSimpleXmlUserScript::tryPropSet($context, $args[0], $name, $args[2]);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::__set() user-script AOT requires a compile-time tree (#35820)'
        );
    }
}
