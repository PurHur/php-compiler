<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::getDocNamespaces() — user-script AOT. */
final class JitSimpleXmlGetDocNamespaces
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SimpleXMLElement::getDocNamespaces', 0, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if ([] === $args) {
            throw new \LogicException('SimpleXMLElement::getDocNamespaces() expects receiver');
        }
        $us = JitSimpleXmlUserScript::tryGetDocNamespaces($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::getDocNamespaces() user-script AOT requires a compile-time tree'
        );
    }
}
