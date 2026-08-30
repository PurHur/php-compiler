<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::count() — user-script AOT (#26863). */
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
     * SimpleXMLElement::hasChildren() — leftover of count AOT (#35827 / php-src sxe.c).
     */
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
            'SimpleXMLElement::hasChildren() user-script AOT requires a compile-time tree (#35827 leftover of #26863)'
        );
    }

    /**
     * SimpleXMLElement::rewind() — leftover of hasChildren AOT (#35844 / php-src sxe.c).
     */
    public static function invokeRewind(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SimpleXMLElement::rewind', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $us = JitSimpleXmlUserScript::tryRewind($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::rewind() user-script AOT requires a compile-time tree (#35844 leftover of #35827)'
        );
    }

    /**
     * SimpleXMLElement::valid() — leftover of hasChildren AOT (#35844 / php-src sxe.c).
     */
    public static function invokeValid(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SimpleXMLElement::valid', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $us = JitSimpleXmlUserScript::tryValid($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::valid() user-script AOT requires a compile-time tree (#35844 leftover of #35827)'
        );
    }

    /**
     * SimpleXMLElement::current() — leftover of hasChildren AOT (#35844 / php-src sxe.c).
     */
    public static function invokeCurrent(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SimpleXMLElement::current', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $us = JitSimpleXmlUserScript::tryCurrent($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::current() user-script AOT requires a compile-time tree (#35844 leftover of #35827)'
        );
    }

    /**
     * SimpleXMLElement::key() — leftover of hasChildren AOT (#35844 / php-src sxe.c).
     */
    public static function invokeKey(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SimpleXMLElement::key', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $us = JitSimpleXmlUserScript::tryKey($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::key() user-script AOT requires a compile-time tree (#35844 leftover of #35827)'
        );
    }

    /**
     * SimpleXMLElement::next() — leftover of hasChildren AOT (#35844 / php-src sxe.c).
     */
    public static function invokeNext(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SimpleXMLElement::next', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $us = JitSimpleXmlUserScript::tryNext($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::next() user-script AOT requires a compile-time tree (#35844 leftover of #35827)'
        );
    }

    /**
     * SimpleXMLElement::getChildren() — leftover of hasChildren AOT (#35844 / php-src sxe.c).
     */
    public static function invokeGetChildren(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SimpleXMLElement::getChildren', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $us = JitSimpleXmlUserScript::tryGetChildren($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::getChildren() user-script AOT requires a compile-time tree (#35844 leftover of #35827)'
        );
    }
}
