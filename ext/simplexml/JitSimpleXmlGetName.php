<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::getName() — user-script AOT (#27535). */
final class JitSimpleXmlGetName
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('SimpleXMLElement::getName() expects receiver');
        }
        $us = JitSimpleXmlUserScript::tryGetName($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::getName() user-script AOT requires a compile-time tree (#27535)'
        );
    }
}
