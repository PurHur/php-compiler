<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::asXML() — user-script AOT (#19306). */
final class JitSimpleXmlAsXml
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SimpleXMLElement::asXML', 0, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if ([] === $args) {
            throw new \LogicException('SimpleXMLElement::asXML() expects receiver');
        }
        $us = JitSimpleXmlUserScript::tryAsXml($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::asXML() user-script AOT requires a compile-time constructed tree (#19306)'
        );
    }
}
