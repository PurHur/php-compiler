<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::__toString() — user-script AOT (#26863). */
final class JitSimpleXmlToString
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SimpleXMLElement::__toString', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $us = JitSimpleXmlUserScript::tryToString($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::__toString() user-script AOT requires a compile-time tree (#26863)'
        );
    }
}
