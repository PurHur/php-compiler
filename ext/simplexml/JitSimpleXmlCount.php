<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::count() / hasChildren() — user-script AOT (#26863, #35827). */
final class JitSimpleXmlCount
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SimpleXMLElement::count', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $us = JitSimpleXmlUserScript::tryCount($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::count() user-script AOT requires a compile-time tree (#26863)'
        );
    }

    /** LLVM lowering for SimpleXMLElement::hasChildren() — leftover of count (#35827). */
    public static function invokeHasChildren(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SimpleXMLElement::hasChildren', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $us = JitSimpleXmlUserScript::tryHasChildren($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::hasChildren() user-script AOT requires a compile-time tree (#35827)'
        );
    }
}
