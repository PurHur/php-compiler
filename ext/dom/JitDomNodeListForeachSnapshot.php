<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Thin-AOT foreach over DOMNodeList via compile-time snapshot → hashtable (#32707, #33082).
 *
 * Iterator protocol and runtime item() inside the foreach CFG both break module
 * verification or abort under nested JIT. For user-script getElementsByTagName lists,
 * materialize every item at Iterator_Reset from compile-time XML (peer #27275).
 * For {@code $el->childNodes} (no tag query), snapshot direct children of loadXML
 * / loadHTML markup (#33082). For {@code $el->attributes} NamedNodeMap, snapshot
 * root open-tag attributes (#33099).
 *
 * php-src: ext/dom/nodelist.c / namednodemap.c — InternalIterator; Zend copies
 * non-array Traversables when foreach stability requires a snapshot.
 */
final class JitDomNodeListForeachSnapshot
{
    /** Set when thin-AOT last fetched DOMNode::$childNodes (#33082). */
    private static bool $lastChildNodesFetch = false;

    /** Set when thin-AOT last fetched DOMElement::$attributes (#33099). */
    private static bool $lastAttributesFetch = false;

    public static function markChildNodesFetch(): void
    {
        self::$lastChildNodesFetch = true;
        self::$lastAttributesFetch = false;
        // Child list is not a tag query — clear so canLower prefers direct children.
        JitDomGetElementsByTagNameUserScript::clearTagQueryState();
        JitDomXPathQueryUserScript::clearQueryState();
    }

    public static function markAttributesFetch(): void
    {
        self::$lastAttributesFetch = true;
        self::$lastChildNodesFetch = false;
        JitDomGetElementsByTagNameUserScript::clearTagQueryState();
        JitDomXPathQueryUserScript::clearQueryState();
    }

    public static function clearChildNodesFetch(): void
    {
        self::$lastChildNodesFetch = false;
    }

    public static function clearAttributesFetch(): void
    {
        self::$lastAttributesFetch = false;
    }

    public static function lastWasChildNodesFetch(): bool
    {
        return self::$lastChildNodesFetch;
    }

    public static function lastWasAttributesFetch(): bool
    {
        return self::$lastAttributesFetch;
    }

    public static function isDomNodeListForeach(?string $containerUserType): bool
    {
        if (self::$lastChildNodesFetch || self::$lastAttributesFetch) {
            return true;
        }
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $lc = strtolower(ltrim($containerUserType, '\\'));

        return 'domnodelist' === $lc
            || 'domnamednodemap' === $lc
            || 'dom\\nodelist' === $lc
            || 'dom\\htmlcollection' === $lc
            || 'dom\\dtdnamednodemap' === $lc
            || 'dom\\namednodemap' === $lc;
    }

    /** True for DOMNodeList / HTMLCollection — not NamedNodeMap (attrs need a different bake). */
    public static function isChildNodesStyleList(?string $containerUserType): bool
    {
        if (self::$lastChildNodesFetch) {
            return true;
        }
        if (self::$lastAttributesFetch) {
            return false;
        }
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $lc = strtolower(ltrim($containerUserType, '\\'));

        return 'domnodelist' === $lc
            || 'dom\\nodelist' === $lc
            || 'dom\\htmlcollection' === $lc;
    }

    public static function isNamedNodeMapStyleList(?string $containerUserType): bool
    {
        if (self::$lastAttributesFetch) {
            return true;
        }
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $lc = strtolower(ltrim($containerUserType, '\\'));

        return 'domnamednodemap' === $lc
            || 'dom\\namednodemap' === $lc
            || 'dom\\dtdnamednodemap' === $lc;
    }

    public static function canLower(
        Context $context,
        JITVariable $array,
        ?string $containerUserType = null
    ): bool {
        if (!JitDomLoadHTMLUserScript::shouldUse($context)) {
            return false;
        }
        $tag = JitDomGetElementsByTagNameUserScript::lastTagQuery();
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        if (null !== $tag && null !== $xml) {
            return true;
        }
        $xpathTag = JitDomXPathQueryUserScript::lastQueryTag();
        if (null !== $xml && null !== $xpathTag && '' !== $xpathTag) {
            return true;
        }
        if (null === $xml) {
            return false;
        }
        // attributes NamedNodeMap (#33099) or childNodes / HTMLCollection (#33082).
        return self::isNamedNodeMapStyleList($containerUserType)
            || self::isChildNodesStyleList($containerUserType);
    }

    public static function compileReset(
        Context $context,
        JITVariable $array,
        JITVariable $slotKey,
        ?string $containerUserType = null
    ): void {
        if (!self::canLower($context, $array, $containerUserType)) {
            throw new \LogicException(
                'DOMNodeList foreach in thin AOT requires compile-time getElementsByTagName/loadXML (#32707/#33082/#33099)'
            );
        }

        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        $tag = JitDomGetElementsByTagNameUserScript::lastTagQuery();
        $xpathTag = JitDomXPathQueryUserScript::lastQueryTag();
        if (null === $tag && null !== $xpathTag) {
            $tag = $xpathTag;
        }
        if (null === $xml) {
            throw new \LogicException('DOMNodeList foreach snapshot missing compile-time XML (#32707/#33082/#33099)');
        }

        $sizeT = $context->getTypeFromString('size_t');
        $attrsMode = self::isNamedNodeMapStyleList($containerUserType);
        $useDirectChildren = !$attrsMode && (
            self::$lastChildNodesFetch
            || null === $tag
            || '' === $tag
        );

        if ($attrsMode) {
            $attrs = DomParseSimpleXmlJitHelper::rootAttributesArgv($xml);
            $count = \count($attrs);
            $ht = HashTableHelper::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $ht,
                $sizeT->constInt($count > 0 ? $count : 1, false)
            );
            for ($i = 0; $i < $count; ++$i) {
                $itemPtr = JitDomNodeListItemUserScript::materializeRootAttrAtCompileTime(
                    $context,
                    $xml,
                    $i
                );
                $elem = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $itemPtr);
                HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt($i, false), $elem);
            }
        } elseif (!$useDirectChildren) {
            $count = DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag);
            $ht = HashTableHelper::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $ht,
                $sizeT->constInt($count > 0 ? $count : 1, false)
            );
            for ($i = 0; $i < $count; ++$i) {
                $itemPtr = JitDomNodeListItemUserScript::materializeItemAtCompileTime($context, $xml, $tag, $i);
                $elem = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $itemPtr);
                HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt($i, false), $elem);
            }
        } else {
            // Direct children of document element (childNodes) (#33082).
            $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
            $count = \count($nodes);
            $ht = HashTableHelper::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $ht,
                $sizeT->constInt($count > 0 ? $count : 1, false)
            );
            for ($i = 0; $i < $count; ++$i) {
                $itemPtr = JitDomNodeListItemUserScript::materializeDirectChildAtCompileTime(
                    $context,
                    $xml,
                    $i
                );
                $elem = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $itemPtr);
                HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt($i, false), $elem);
            }
        }

        $map = $context->structFieldMap['__hashtable__'];
        $countVal = $sizeT->constInt($count, false);
        $context->builder->store($countVal, $context->builder->structGep($ht, $map['numElements']));
        $context->builder->store($countVal, $context->builder->structGep($ht, $map['nextFreeElement']));

        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $context->foreachDomNodeListSlots[$context->foreachSlotMapKey($slotKey)] = $htVar;
        self::clearChildNodesFetch();
        self::clearAttributesFetch();

        if (!isset($context->foreachIndexSlots[$context->foreachSlotMapKey($slotKey)])) {
            $context->foreachIndexSlots[$context->foreachSlotMapKey($slotKey)] = BasicBlockHelper::entryAlloca($context, $sizeT);
        }
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $invalid = $context->builder->sub($zero, $one);
        $context->builder->store($invalid, $context->foreachIndexSlots[$context->foreachSlotMapKey($slotKey)]);
    }
}
