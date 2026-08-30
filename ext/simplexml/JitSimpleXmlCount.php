<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::count() / Iterator leftover (#26863, #35827, #35844). */
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

    /**
     * SimpleXMLElement Iterator / RecursiveIterator leftover of hasChildren (#35827 / #35844).
     */
    public static function invokeNamed(Context $context, string $method, JITVariable ...$args): Value
    {
        $label = 'SimpleXMLElement::'.$method;
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, $label, 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $us = JitSimpleXmlUserScript::tryIteratorMethod($context, $method, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            $label.'() user-script AOT requires a compile-time tree (#35844 leftover of #35827)'
        );
    }

    /**
     * SimpleXMLElement::hasChildren() — leftover of count AOT (#35827 / php-src sxe.c).
     */
    public static function invokeHasChildren(Context $context, JITVariable ...$args): Value
    {
        return self::invokeNamed($context, 'hasChildren', ...$args);
    }
}
