<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::xpath() — user-script AOT (#22720). */
final class JitSimpleXmlXpath
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SimpleXMLElement::xpath', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if (\count($args) < 2) {
            throw new \LogicException('SimpleXMLElement::xpath() expects receiver and path');
        }
        $us = JitSimpleXmlUserScript::tryXpath($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::xpath() user-script AOT requires a compile-time constructed tree and literal path (#22720)'
        );
    }
}
