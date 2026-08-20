<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Thin-AOT foreach over DOMNodeList via compile-time snapshot → hashtable (#32707).
 *
 * Iterator protocol and runtime item() inside the foreach CFG both break module
 * verification or abort under nested JIT. For user-script getElementsByTagName lists,
 * materialize every item at Iterator_Reset from compile-time XML (peer #27275).
 *
 * php-src: ext/dom/nodelist.c — InternalIterator; Zend copies non-array Traversables
 * when foreach stability requires a snapshot.
 */
final class JitDomNodeListForeachSnapshot
{
    public static function isDomNodeListForeach(?string $containerUserType): bool
    {
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $lc = strtolower(ltrim($containerUserType, '\\'));

        return 'domnodelist' === $lc
            || 'domnamednodemap' === $lc
            || 'dom\\nodelist' === $lc
            || 'dom\\htmlcollection' === $lc
            || 'dom\\dtdnamednodemap' === $lc;
    }

    public static function canLower(Context $context, JITVariable $array): bool
    {
        if (!JitDomLoadHTMLUserScript::shouldUse($context)) {
            return false;
        }
        $tag = JitDomGetElementsByTagNameUserScript::lastTagQuery();
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        if (null === $tag || null === $xml) {
            $xpathTag = JitDomXPathQueryUserScript::lastQueryTag();

            return null !== $xml && null !== $xpathTag && '' !== $xpathTag;
        }

        return true;
    }

    public static function compileReset(Context $context, JITVariable $array, JITVariable $slotKey): void
    {
        if (!self::canLower($context, $array)) {
            throw new \LogicException(
                'DOMNodeList foreach in thin AOT requires compile-time getElementsByTagName/loadXML (#32707)'
            );
        }

        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        $tag = JitDomGetElementsByTagNameUserScript::lastTagQuery();
        $xpathTag = JitDomXPathQueryUserScript::lastQueryTag();
        if (null === $tag && null !== $xpathTag) {
            $tag = $xpathTag;
        }
        if (null === $xml || null === $tag) {
            throw new \LogicException('DOMNodeList foreach snapshot missing compile-time XML/tag (#32707)');
        }

        $count = DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag);
        $sizeT = $context->getTypeFromString('size_t');
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

        $map = $context->structFieldMap['__hashtable__'];
        $countVal = $sizeT->constInt($count, false);
        $context->builder->store($countVal, $context->builder->structGep($ht, $map['numElements']));
        $context->builder->store($countVal, $context->builder->structGep($ht, $map['nextFreeElement']));

        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $context->foreachDomNodeListSlots[$context->foreachSlotMapKey($slotKey)] = $htVar;

        if (!isset($context->foreachIndexSlots[$context->foreachSlotMapKey($slotKey)])) {
            $context->foreachIndexSlots[$context->foreachSlotMapKey($slotKey)] = BasicBlockHelper::entryAlloca($context, $sizeT);
        }
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $invalid = $context->builder->sub($zero, $one);
        $context->builder->store($invalid, $context->foreachIndexSlots[$context->foreachSlotMapKey($slotKey)]);
    }
}
