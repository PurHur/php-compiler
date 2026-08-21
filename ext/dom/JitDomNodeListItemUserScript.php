<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMNodeList::item() (#18493, #26752, #27275). */
final class JitDomNodeListItemUserScript
{
    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }
        // Fold when the index operand is an LLVM i64 constant. Loop `$i`
        // keeps stale compileTimeLong=0 as KIND_VALUE (#32831 / peer #32605).
        $index = null;
        $arg = $args[1];
        if (
            null !== $arg->value
            && \PHPLLVM\Value::KIND_CONSTANT_INT === $arg->value->getKind()
        ) {
            $index = $arg->compileTimeLong;
            if (null === $index && null !== $arg->compileTimeString && is_numeric($arg->compileTimeString)) {
                $index = (int) $arg->compileTimeString;
            }
            if (null === $index) {
                $index = (int) $context->llvm->lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }

        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        $queryTag = JitDomXPathQueryUserScript::lastQueryTag();
        $tagQuery = JitDomGetElementsByTagNameUserScript::lastTagQuery();
        $markup = JitDomLoadXMLUserScript::lastCompileTimeXml()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();

        // Dynamic index: compile-time getElementsByTagName / XPath tag list (#33063).
        // OwnerAware ABI aborts on thin-AOT NodeList without CHILD_NODES_OWNER.
        if (null === $index) {
            if (null !== $xml && null !== $queryTag && '' !== $queryTag) {
                return self::materializeDynamicIndexQueryMatch($context, $xml, $queryTag, $arg);
            }
            if (null !== $tagQuery && null !== $markup) {
                return self::materializeDynamicIndexQueryMatch($context, $markup, $tagQuery, $arg);
            }

            return null;
        }
        if ($index < 0) {
            return null;
        }

        // XPath //tag (and predicate) lists: materialize Nth match with attrs (#27275).
        if (null !== $xml && null !== $queryTag && '' !== $queryTag) {
            return self::materializeNthQueryMatch($context, $xml, $queryTag, $index);
        }

        // getElementsByTagNameNS live list: materialize Nth NS match (#32415).
        $nsQuery = JitDomGetElementsByTagNameUserScript::lastNsQuery();
        if (null !== $xml && null !== $nsQuery) {
            return self::materializeNthNsMatch($context, $xml, $nsQuery[0], $nsQuery[1], $index);
        }

        // Legacy XPath evaluate/query cache hit (item(0) only).
        if (0 === $index) {
            $cacheKey = JitDomXPathQueryUserScript::lastCacheKey();
            if (null !== $cacheKey) {
                $keyStr = $context->builder->load($context->constantStringFromString($cacheKey));
                $found = DomUserScriptElementCacheLlvm::lookupObject($context, $keyStr);

                return self::boxObject($context, $found);
            }
        }

        // getElementsByTagName live list — any index, including "*" (#26752 / #33063).
        if (null !== $tagQuery && null !== $markup) {
            return self::materializeNthQueryMatch($context, $markup, $tagQuery, $index);
        }
        if (0 !== $index) {
            return null;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return null;
        }
        $pinned = DomUserScriptPinnedRootLlvm::load($context);
        if (null === $pinned) {
            return null;
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nodelist_item_us_first');
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_FIRST_CHILD)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_FIRST_CHILD, JITVariable::TYPE_VALUE);
        }
        $firstObj = self::loadChildObjectFromSlot(
            $context,
            $objectType,
            $pinned,
            VmDom::PROP_FIRST_CHILD
        );

        return self::boxObject($context, $firstObj);
    }

    /**
     * Runtime item($i) over a compile-time tag list via select ladder (#33063).
     *
     * Avoids DomNodeListItemRuntime ABI (aborts on thin-AOT nodes without an owner).
     */
    private static function materializeDynamicIndexQueryMatch(
        Context $context,
        string $xml,
        string $tag,
        JITVariable $indexArg
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nodelist_item_dyn_idx');
        $count = DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag);
        $i64 = $context->getTypeFromString('int64');
        // Same index lowering as JitDomNodeListItem::loadIntArg.
        if (JITVariable::TYPE_NATIVE_LONG === $indexArg->type) {
            $indexVal = $context->helper->loadValue($indexArg);
        } elseif (JITVariable::TYPE_VALUE === $indexArg->type) {
            $indexVal = $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                JitValueBox::valuePtrFromVariable($context, $indexArg)
            );
        } else {
            throw new \LogicException('DOMNodeList::item() dynamic index must be an integer (#33063)');
        }
        $out = self::boxNull($context);
        for ($i = 0; $i < $count; ++$i) {
            $cand = self::materializeNthQueryMatch($context, $xml, $tag, $i);
            $isI = $context->builder->icmp(
                Builder::INT_EQ,
                $indexVal,
                $i64->constInt($i, false)
            );
            $out = $context->builder->select($isI, $cand, $out);
        }

        return $out;
    }

    /**
     * Materialize getElementsByTagNameNS() NodeList::item($index) (#32415, #32511).
     *
     * php-src: ext/dom/nodelist.c php_dom_nodelist_item + element.c xmlFirstElementChild.
     */
    private static function materializeNthNsMatch(
        Context $context,
        string $xml,
        string $namespaceUri,
        string $localName,
        int $index
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nodelist_item_ns_nth');
        $match = JitDomGetElementsByTagNameUserScript::lastNsQueryFromElement()
            ? DomParseSimpleXmlJitHelper::nthElementByTagNameNSFromDescendantsArgv(
                $xml,
                $namespaceUri,
                $localName,
                $index
            )
            : DomParseSimpleXmlJitHelper::nthElementByTagNameNSArgv(
                $xml,
                $namespaceUri,
                $localName,
                $index
            );
        if (null === $match) {
            return self::boxNull($context);
        }
        $ns = '' === $match['ns'] ? '' : $match['ns'];
        $element = JitDomCreateElementNS::materializeElementNSFromLiterals(
            $context,
            $ns,
            $match['qname'],
            ''
        );

        return self::boxObject($context, $element);
    }

    /**
     * Compile-time NodeList::item($index) for foreach snapshot (#32707).
     */
    public static function materializeItemAtCompileTime(
        Context $context,
        string $xml,
        string $tag,
        int $index
    ): Value {
        return self::materializeNthQueryMatch($context, $xml, $tag, $index);
    }

    /**
     * Materialize //tag NodeList::item($index) from compile-time XML (#27275).
     *
     * Seeds DomUserScriptAttributeCacheLlvm so getAttribute() works on the result.
     */
    private static function materializeNthQueryMatch(
        Context $context,
        string $xml,
        string $tag,
        int $index
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nodelist_item_xpath_nth');
        $position = $index + 1;
        $openTag = DomParseSimpleXmlJitHelper::nthTagOpenTagArgv($xml, $tag, $position);
        if (null === $openTag) {
            return self::boxNull($context);
        }
        $text = DomParseSimpleXmlJitHelper::nthTagTextArgv($xml, $tag, $position) ?? '';
        $htmlLit = JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        if (null !== $htmlLit && $htmlLit === $xml) {
            $text = VmDom::decodeHtmlCharacterReferences($text);
            $from = \PHPCompiler\ext\iconv\CharsetEngine::canonicalize(self::htmlLoadDecodeEncoding($htmlLit));
            if (null !== $from && 'UTF-8' !== $from) {
                $converted = \PHPCompiler\ext\iconv\CharsetEngine::convert($from, 'UTF-8', $text);
                if (false !== $converted) {
                    $text = $converted;
                }
            }
        }
        // getElementsByTagName("*"): open-tag carries the real element name (#33063).
        $elementName = $tag;
        if ('*' === $tag) {
            $elementName = DomParseSimpleXmlJitHelper::tagNameFromOpenTagArgv($openTag) ?? 'div';
        }
        $element = JitDomCreateElement::materializeElementWithTextContent($context, $elementName, $text);
        foreach (DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv($openTag) as $attrPair) {
            $qname = $attrPair['qname'];
            $value = $attrPair['value'];
            $pos = strpos($qname, ':');
            $local = false === $pos ? $qname : substr($qname, $pos + 1);
            $attr = JitDomAttributeNodeNS::materializeAttrFromLiterals(
                $context,
                '',
                $qname,
                $value
            );
            DomUserScriptAttributeCacheLlvm::storeLiteral($context, '', $local, $attr, $value);
            if ($local !== $qname) {
                DomUserScriptAttributeCacheLlvm::storeLiteral($context, '', $qname, $attr, $value);
            }
        }

        return self::boxObject($context, $element);
    }

    private static function loadChildObjectFromSlot(
        Context $context,
        Object_ $objectType,
        Value $receiver,
        string $prop
    ): Value {
        $childSlot = $objectType->propertySlotFor($receiver, 'DOMElement', $prop);
        $slotPtr = $context->builder->load($childSlot);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNullSlot = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, 'dom_nli_us_'.$prop.'_null');
        $readBlock = BasicBlockHelper::append($context, 'dom_nli_us_'.$prop.'_read');
        $merge = BasicBlockHelper::append($context, 'dom_nli_us_'.$prop.'_merge');
        $context->builder->branchIf($isNullSlot, $nullBlock, $readBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($readBlock);
        $valuePtr = $context->builder->pointerCast(
            $slotPtr,
            $context->getTypeFromString('__value__*')
        );
        $childObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $nullBlock);
        $phi->addIncoming($childObj, $readBlock);

        return $phi;
    }

    private static function boxObject(Context $context, Value $object): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $object
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function boxNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    /** Mirror VmDom::loadHTML charset selection for compile-time HTML NodeList text (#22023). */
    private static function htmlLoadDecodeEncoding(string $html): string
    {
        if (1 === preg_match('/<meta\b[^>]*charset\s*=\s*["\']?([^"\'>\s;]+)/i', $html, $m)) {
            return $m[1];
        }
        if (1 === preg_match('/<\?xml\b[^>]*encoding\s*=\s*["\']([^"\']+)/i', $html, $m)) {
            return $m[1];
        }

        return 'ISO-8859-1';
    }
}
