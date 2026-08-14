<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::attributes() — user-script AOT (#27535). */
final class JitSimpleXmlAttributes
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SimpleXMLElement::attributes', 0, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if ([] === $args) {
            throw new \LogicException('SimpleXMLElement::attributes() expects receiver');
        }
        $us = JitSimpleXmlUserScript::tryAttributes($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::attributes() user-script AOT requires a compile-time tree (#27535)'
        );
    }
}
