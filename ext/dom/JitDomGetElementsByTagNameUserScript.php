<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMDocument::getElementsByTagName() (#18478). */
final class JitDomGetElementsByTagNameUserScript
{
    private const CLASS_NODELIST = 'DOMNodeList';

    private static ?string $lastNsUri = null;

    private static ?string $lastNsLocal = null;

    /**
     * NS uri/local from the last getElementsByTagNameNS — survives
     * {@see clearTagQueryState()} so held NS lists still item()/decrement
     * after an intervening childNodes fetch (#34995 / peer #34646).
     */
    private static ?string $liveItemNsUri = null;

    private static ?string $liveItemNsLocal = null;

    /** Last NS query was DOMElement::getElementsByTagNameNS (skip document element; #32511). */
    private static bool $lastNsFromElement = false;

    /** Survives clearTagQueryState with {@see $liveItemNsUri} (#34995). */
    private static bool $liveItemNsFromElement = false;

    private static ?string $lastTagQuery = null;

    /**
     * Tag from the last getElementsByTagName() — survives {@see clearTagQueryState()}
     * so held NodeList::item() still live-walks after an intervening childNodes /
     * attributes fetch (#34646 / php-src nodelist.c).
     */
    private static ?string $liveItemTagQuery = null;

    /**
     * Last tag query was DOMElement::getElementsByTagName — descendants only,
     * exclude the context element (#34780 / php-src element.c).
     */
    private static bool $lastTagQueryFromElement = false;

    public static function lastTagQuery(): ?string
    {
        return self::$lastTagQuery;
    }

    /**
     * Tag for NodeList::item() when {@see lastTagQuery()} was cleared by
     * childNodes/attributes fetch (#34646).
     */
    public static function liveItemTagQuery(): ?string
    {
        return self::$liveItemTagQuery;
    }

    /** True when the active tag list is Element::getElementsByTagName (#34780). */
    public static function lastTagQueryFromElement(): bool
    {
        return self::$lastTagQueryFromElement;
    }

    /** Clear tag query so childNodes/attributes foreach does not reuse a stale tag (#33082/#33099). */
    public static function clearTagQueryState(): void
    {
        self::$lastTagQuery = null;
        // Keep $liveItemTagQuery / $lastTagQueryFromElement — held Element/Document
        // getElementsByTagName lists must still item() (#34646 / #34780).
        // Keep $liveItemNs* — held getElementsByTagNameNS lists (#34995).
        self::$lastNsUri = null;
        self::$lastNsLocal = null;
        self::$lastNsFromElement = false;
    }

    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    /** @return null|array{0: string, 1: string} namespace URI + localName from last NS query (#32415). */
    public static function lastNsQuery(): ?array
    {
        if (null !== self::$lastNsUri && null !== self::$lastNsLocal) {
            return [self::$lastNsUri, self::$lastNsLocal];
        }
        // Held NS list after childNodes/attributes fetch (#34995).
        if (null !== self::$liveItemNsUri && null !== self::$liveItemNsLocal) {
            return [self::$liveItemNsUri, self::$liveItemNsLocal];
        }

        return null;
    }

    public static function lastNsQueryFromElement(): bool
    {
        if (null !== self::$lastNsUri && null !== self::$lastNsLocal) {
            return self::$lastNsFromElement;
        }

        return self::$liveItemNsFromElement;
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        self::$lastNsUri = null;
        self::$lastNsLocal = null;
        self::$lastNsFromElement = false;
        self::$liveItemNsUri = null;
        self::$liveItemNsLocal = null;
        self::$liveItemNsFromElement = false;
        self::$lastTagQuery = null;
        self::$liveItemTagQuery = null;
        self::$lastTagQueryFromElement = false;
        JitDomNodeListForeachSnapshot::clearChildNodesFetch();
        JitDomNodeListForeachSnapshot::clearAttributesFetch();
        if (\count($args) < 2) {
            return null;
        }
        // Soft-null under non-strict is '' (Z_PARAM_STR); keep UserScript path so thin-AOT
        // does not call the empty-tag ABI bridge that segfaults (#29959).
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            if ($context->callerStrictTypes) {
                return null;
            }
            $tagLit = '';
        } else {
            $tagLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $tagLit) {
                return null;
            }
        }
        // Prefer this document's loadXML binding — never steal lastCompileTimeXml from
        // another document (importNode destination counted the source tree; #34630 /
        // peer saveXML #33697).
        $markup = JitDomLoadXMLUserScript::compileTimeXmlFor($args[0]);
        // Fall back to last loadXML literal when the receiver binding is unset (#34936).
        if (null === $markup) {
            $markup = JitDomLoadXMLUserScript::lastCompileTimeXml();
        }
        if (null === $markup) {
            // HTML-only scripts: no XML literal exists to steal.
            $markup = JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        }
        if (null === $markup) {
            // Receiver never loadXML'd (createElement / importNode dest). Seed length
            // from the live pinned tree so pending+source-count cannot double (#34630).
            self::$lastTagQuery = $tagLit;
            self::$liveItemTagQuery = $tagLit;
            DomUserScriptLiveTagListLlvm::resyncCountFromLiveTree($context, $tagLit);

            return self::boxNodeList($context, 0);
        }
        self::$lastTagQuery = $tagLit;
        self::$liveItemTagQuery = $tagLit;
        // LiveSlots mutations after loadXML leave compile-time markup stale (#33918).
        if (JitDomLoadXMLUserScript::treeMutatedSinceLoad()) {
            DomUserScriptLiveTagListLlvm::resyncCountFromLiveTree($context, $tagLit);

            return self::boxNodeList($context, 0);
        }
        $count = DomParseSimpleXmlJitHelper::countTagArgv($markup, $tagLit);
        // Preserve live appendChild increments when re-querying the same tag (#28605).
        DomUserScriptLiveTagListLlvm::initCount($context, $tagLit, $count);

        return self::boxNodeList($context, $count);
    }

    /**
     * DOMDocument::getElementsByTagNameNS() — compile-time live list (#32415).
     *
     * php-src: ext/dom/php_dom.c PHP_METHOD(DOMDocument, getElementsByTagNameNS).
     */
    public static function tryInvokeNS(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 3) {
            return null;
        }
        $nsLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $nsLit && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            $nsLit = '';
        }
        if ($context->callerStrictTypes && (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false))) {
            return null;
        }
        $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $nsLit || null === $localLit) {
            return null;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }
        self::$lastNsUri = $nsLit;
        self::$lastNsLocal = $localLit;
        self::$lastNsFromElement = false;
        self::$liveItemNsUri = $nsLit;
        self::$liveItemNsLocal = $localLit;
        self::$liveItemNsFromElement = false;
        $tagKey = 'ns|'.$nsLit.'|'.$localLit;
        // LiveSlots mutations leave compile-time NS counts stale (#34995 / peer #33918).
        if (JitDomLoadXMLUserScript::treeMutatedSinceLoad()) {
            DomUserScriptLiveTagListLlvm::resyncCountFromLiveTreeNs($context, $nsLit, $localLit, false);

            return self::boxNodeList($context, 0);
        }
        $count = DomParseSimpleXmlJitHelper::countElementsByTagNameNSArgv($xml, $nsLit, $localLit);
        DomUserScriptLiveTagListLlvm::initCount($context, $tagKey, $count);

        return self::boxNodeList($context, $count);
    }

    /**
     * DOMElement::getElementsByTagName() — descendants of documentElement (#32454).
     *
     * php-src: ext/dom/element.c PHP_METHOD(DOMElement, getElementsByTagName).
     */
    public static function tryInvokeFromElement(Context $context, JITVariable ...$args): ?Value
    {
        self::$lastNsUri = null;
        self::$lastNsLocal = null;
        self::$lastNsFromElement = false;
        self::$liveItemNsUri = null;
        self::$liveItemNsLocal = null;
        self::$liveItemNsFromElement = false;
        // Peer Document tryInvoke: seed tag so NodeList::item() does not fall through
        // to pinned-root firstChild (#34780).
        self::$lastTagQuery = null;
        self::$liveItemTagQuery = null;
        self::$lastTagQueryFromElement = false;
        JitDomNodeListForeachSnapshot::clearChildNodesFetch();
        JitDomNodeListForeachSnapshot::clearAttributesFetch();
        if (\count($args) < 2) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            if ($context->callerStrictTypes) {
                return null;
            }
            $tagLit = '';
        } else {
            $tagLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $tagLit) {
                return null;
            }
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        $count = DomParseSimpleXmlJitHelper::countDescendantTagArgv($inner, $tagLit);
        self::$lastTagQuery = $tagLit;
        self::$liveItemTagQuery = $tagLit;
        self::$lastTagQueryFromElement = true;
        DomUserScriptLiveTagListLlvm::initCount($context, $tagLit, $count);

        return self::boxNodeList($context, $count);
    }

    /**
     * DOMElement::getElementsByTagNameNS() — descendants of documentElement (#32511).
     *
     * php-src: ext/dom/element.c PHP_METHOD(DOMElement, getElementsByTagNameNS).
     */
    public static function tryInvokeFromElementNS(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 3) {
            return null;
        }
        $nsLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $nsLit && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            $nsLit = '';
        }
        if ($context->callerStrictTypes && (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false))) {
            return null;
        }
        $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $nsLit || null === $localLit) {
            return null;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }
        self::$lastNsUri = $nsLit;
        self::$lastNsLocal = $localLit;
        self::$lastNsFromElement = true;
        self::$liveItemNsUri = $nsLit;
        self::$liveItemNsLocal = $localLit;
        self::$liveItemNsFromElement = true;
        $tagKey = 'elns|'.$nsLit.'|'.$localLit;
        if (JitDomLoadXMLUserScript::treeMutatedSinceLoad()) {
            DomUserScriptLiveTagListLlvm::resyncCountFromLiveTreeNs($context, $nsLit, $localLit, true);

            return self::boxNodeList($context, 0);
        }
        $count = DomParseSimpleXmlJitHelper::countElementsByTagNameNSFromDescendantsArgv(
            $xml,
            $nsLit,
            $localLit
        );
        DomUserScriptLiveTagListLlvm::initCount($context, $tagKey, $count);

        return self::boxNodeList($context, $count);
    }

    private static function boxNodeList(Context $context, int $length): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NODELIST);
        // Define childNodes-owner slot before allocate so JitDomNodeListLength's runtime
        // owner check reads null (tag lists) not garbage past the allocation (#28605).
        if (!$objectType->hasProperty($classId, VmDom::PROP_CHILD_NODES_OWNER)) {
            $objectType->defineProperty($classId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($classId, 'length')) {
            $objectType->defineProperty($classId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }
        $list = $objectType->allocate($classId);
        $objectType->markObjectConstructed($list);
        $lengthVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($length, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, self::CLASS_NODELIST, 'length'),
            $lengthVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $list
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
