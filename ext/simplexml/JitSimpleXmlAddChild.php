<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::addChild() — user-script AOT (#19306). */
final class JitSimpleXmlAddChild
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('SimpleXMLElement::addChild() expects receiver and name');
        }
        $us = JitSimpleXmlUserScript::tryAddChild($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::addChild() user-script AOT requires compile-time literal args after construct (#19306)'
        );
    }
}
