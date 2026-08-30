<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::getNamespaces() — user-script AOT. */
final class JitSimpleXmlGetNamespaces
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SimpleXMLElement::getNamespaces', 0, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if ([] === $args) {
            throw new \LogicException('SimpleXMLElement::getNamespaces() expects receiver');
        }
        $us = JitSimpleXmlUserScript::tryGetNamespaces($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::getNamespaces() user-script AOT requires a compile-time tree'
        );
    }
}
