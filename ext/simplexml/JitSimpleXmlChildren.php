<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::children() — user-script AOT (#27535). */
final class JitSimpleXmlChildren
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('SimpleXMLElement::children() expects receiver');
        }
        $us = JitSimpleXmlUserScript::tryChildren($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::children() user-script AOT requires a compile-time tree (#27535)'
        );
    }
}
