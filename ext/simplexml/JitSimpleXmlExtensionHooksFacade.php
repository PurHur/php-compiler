<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\SimpleXmlExtensionHooks;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * simplexml surfaces for lib/JIT Call SimpleXMLElement* (#36204).
 *
 * php-src: ext/simplexml/sxe.c — SimpleXMLElement construct + method thin-AOT.
 * Registered from {@see Module::jitInit} so Call files do not import ext/simplexml.
 */
final class JitSimpleXmlExtensionHooksFacade implements SimpleXmlExtensionHooks
{
    public function construct(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlConstruct::invoke($context, ...$args);
    }

    public function addChild(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlAddChild::invoke($context, ...$args);
    }

    public function addAttribute(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlAddChild::invokeAddAttribute($context, ...$args);
    }

    public function asXml(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlAsXml::invoke($context, ...$args);
    }

    public function attributes(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlAttributes::invoke($context, ...$args);
    }

    public function children(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlChildren::invoke($context, ...$args);
    }

    public function count(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlCount::invoke($context, ...$args);
    }

    public function countNamed(Context $context, string $name, JITVariable ...$args): Value
    {
        return JitSimpleXmlCount::invokeNamed($context, $name, ...$args);
    }

    public function get(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlGet::invoke($context, ...$args);
    }

    public function set(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlGet::invokeSet($context, ...$args);
    }

    public function getDocNamespaces(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlGetDocNamespaces::invoke($context, ...$args);
    }

    public function getName(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlGetName::invoke($context, ...$args);
    }

    public function getNamespaces(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlGetNamespaces::invoke($context, ...$args);
    }

    public function offsetGet(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlOffsetGet::invoke($context, ...$args);
    }

    public function offsetUnset(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlOffsetGet::invokeUnset($context, ...$args);
    }

    public function offsetSet(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlOffsetGet::invokeSet($context, ...$args);
    }

    public function offsetExists(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlOffsetGet::invokeExists($context, ...$args);
    }

    public function registerXPathNamespace(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlRegisterXPathNamespace::invoke($context, ...$args);
    }

    public function toString(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlToString::invoke($context, ...$args);
    }

    public function xpath(Context $context, JITVariable ...$args): Value
    {
        return JitSimpleXmlXpath::invoke($context, ...$args);
    }
}
