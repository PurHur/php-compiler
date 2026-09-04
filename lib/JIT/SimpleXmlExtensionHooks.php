<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * simplexml extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/simplexml/JitSimpleXmlExtensionHooksFacade.php}; Call
 * SimpleXMLElement* files must not import {@code ext\simplexml}.
 */
interface SimpleXmlExtensionHooks
{
    /** SimpleXMLElement::__construct() user-script AOT. */
    public function construct(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::addChild() user-script AOT. */
    public function addChild(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::addAttribute() user-script AOT. */
    public function addAttribute(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::asXML() / saveXML() user-script AOT. */
    public function asXml(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::attributes() user-script AOT. */
    public function attributes(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::children() user-script AOT. */
    public function children(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::count() user-script AOT. */
    public function count(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement Iterator / RecursiveIterator leftover methods. */
    public function countNamed(Context $context, string $name, Variable ...$args): Value;

    /** SimpleXMLElement::__get() user-script AOT. */
    public function get(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::__set() user-script AOT. */
    public function set(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::getDocNamespaces() user-script AOT. */
    public function getDocNamespaces(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::getName() user-script AOT. */
    public function getName(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::getNamespaces() user-script AOT. */
    public function getNamespaces(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::offsetGet() user-script AOT. */
    public function offsetGet(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::offsetUnset() user-script AOT. */
    public function offsetUnset(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::offsetSet() user-script AOT. */
    public function offsetSet(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::offsetExists() user-script AOT. */
    public function offsetExists(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::registerXPathNamespace() user-script AOT. */
    public function registerXPathNamespace(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::__toString() user-script AOT. */
    public function toString(Context $context, Variable ...$args): Value;

    /** SimpleXMLElement::xpath() user-script AOT. */
    public function xpath(Context $context, Variable ...$args): Value;
}
