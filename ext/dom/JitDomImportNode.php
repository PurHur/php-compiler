<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomImportNodeRuntime;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::importNode() (#19212, #32350, #33097, #33362).
 *
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, importNode) → xmlDocCopyNode.
 * Thin-standalone AOT cannot return NestedJIT object pointers (property fetch
 * aborts; contrast adoptNode #29853 which reuses the caller-side node). Materialize
 * a user-script DOMElement instead — tag/inner XML from compile-time loadXML
 * (#32350) or loadHTML getElementById (#19212). `$deep` must gate InnerXml (#33097).
 * Attributes are always copied (xmlDocCopyNode); #33097 only gated children (#33362).
 */
final class JitDomImportNode
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::importNode() expects receiver and node');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_import_node_cont');

        if (JitDomRequireDomNodeArg::guardOrAbort($context, $args[1], 'DOMDocument::importNode', 1, 'node')) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            // php-src default $deep=false — user-script path must not always copy InnerXml (#33097).
            $deep = self::compileTimeDeep($args[2] ?? null);

            return self::invokeUserScriptMaterialize($context, $args[0], $args[1], $deep);
        }

        DomImportNodeRuntime::ensureLinked($context);
        $document = self::loadObjectArg($context, $args[0]);
        $node = self::loadObjectArg($context, $args[1]);
        $deep = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[2])) {
            $deep = self::loadBoolAsInt($context, $args[2]);
        }
        $imported = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_NAME),
            $document,
            $node,
            $deep
        );

        return self::boxObjectResult($context, $imported);
    }

    public static function invokeGetAttribute(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::getAttribute() expects receiver and name');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_get_attr_cont');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            // Fallback only: DomElementGetAttribute prefers live Attr / valueByKey first.
            // Never return a hardcoded HTML id for an unrelated attribute name (#32956).
            $nameLit = null;
            if (JITVariable::TYPE_STRING === $args[1]->type) {
                $nameLit = $args[1]->compileTimeString
                    ?? \PHPCompiler\JIT\JitStringBuiltinArg::compileTimeLiteral($args[1]);
            }
            $valueLit = '';
            if (null !== $nameLit) {
                $cached = DomUserScriptAttributeCacheLlvm::literalValue('', $nameLit);
                if (null !== $cached) {
                    $valueLit = $cached;
                } else {
                    $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
                    if (null !== $xml) {
                        foreach (DomParseSimpleXmlJitHelper::rootAttributesArgv($xml) as $pair) {
                            $qname = $pair['qname'];
                            $pos = strpos($qname, ':');
                            $local = false === $pos ? $qname : substr($qname, $pos + 1);
                            if ($nameLit === $qname || $nameLit === $local) {
                                $valueLit = $pair['value'];
                                break;
                            }
                        }
                    } else {
                        $parsed = JitDomLoadHTMLUserScript::lastGetElementByIdHit()
                            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsed();
                        // Only the id attribute itself may use the HTML id stub (#19212).
                        if (null !== $parsed && ('id' === $nameLit)) {
                            $valueLit = $parsed['id'] ?? 'target';
                        }
                    }
                }
            }
            $str = $context->builder->load($context->constantStringFromString($valueLit));
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $str
            );

            return JitValueBox::normalizeValuePtr($context, $ptr);
        }

        DomImportNodeRuntime::ensureGetAttributeLinked($context);
        $element = self::loadObjectArg($context, $args[0]);
        $name = self::loadStringArg($context, $args[1]);
        $value = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_GET_ATTRIBUTE),
            $element,
            $name
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $value
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    /**
     * Thin AOT: clone via user-script materialize (nodeName/tagName/INNER_XML slots).
     * NestedJIT object returns abort on property fetch (#29853 / #32350).
     *
     * Prefer the *source node* compile-time tag/inner (#32987) — lastCompileTimeXml is
     * the globally last loadXML and is wrong when importing across two documents.
     *
     * `$deep` mirrors php-src xmlDocCopyNode: shallow omits child markup (#33097).
     * Attribute suffix is always applied (deep does not gate attrs; #33362).
     */
    private static function invokeUserScriptMaterialize(
        Context $context,
        JITVariable $documentVar,
        JITVariable $sourceNode,
        bool $deep
    ): Value {
        $html = JitDomLoadHTMLUserScript::lastGetElementByIdHit()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsed();
        $tag = 'div';
        $text = '';
        $inner = '';
        $id = 'target';
        $fromXml = false;
        $srcTag = $sourceNode->compileTimeDomTagName ?? null;
        if (null !== $srcTag && '' !== $srcTag) {
            $tag = $srcTag;
            $inner = $sourceNode->compileTimeDomInnerXml ?? '';
            $fromXml = true;
        }
        if (!$fromXml) {
            $xml = $sourceNode->compileTimeDomLoadXml
                ?? JitDomLoadXMLUserScript::compileTimeXmlFor($sourceNode)
                ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
            if (null !== $xml) {
                $root = self::parseCompileTimeXmlRoot($xml);
                if (null !== $root) {
                    $tag = $root['tag'];
                    $inner = $root['inner'];
                    $fromXml = true;
                }
            }
        }
        if (!$fromXml && null !== $html) {
            $tag = $html['tag'] ?? $tag;
            $text = $html['text'] ?? '';
            $id = $html['id'] ?? $id;
        }

        // Shallow importNode: element only — no child InnerXml / text nodes (#33097).
        if (!$deep) {
            $inner = '';
            $text = '';
        }

        $attrInfo = self::resolveSourceAttrInfo($sourceNode, $tag);

        $element = JitDomCreateElement::materializeForUserScriptDocument(
            $context,
            $documentVar,
            $tag,
            $text
        );
        if ($deep && '' !== $inner) {
            JitDomCreateElement::storeUserScriptInnerXml($context, $element, $inner);
        }
        // xmlDocCopyNode always copies attributes; #33097 only cleared children (#33362).
        if ('' !== $attrInfo['attrs']) {
            JitDomCreateElement::storeUserScriptXmlnsAttr($context, $element, $attrInfo['attrs']);
        }
        if ([] !== $attrInfo['pairs']) {
            JitDomCreateElement::storeAttributesPresence($context, $element, $attrInfo['pairs']);
        }
        if (!$fromXml) {
            self::storeElementInIdMap($context, $documentVar, $id, $element);
        }

        return self::boxObjectResult($context, $element);
    }

    /**
     * Name→value attrs for compile-time INNER_XML sync after importNode (#33362).
     *
     * @return array<string, string>|null
     */
    public static function compileTimeAttributesFor(JITVariable $sourceNode, ?string $tag = null): ?array
    {
        $tag = $tag ?? ($sourceNode->compileTimeDomTagName ?? '');
        $info = self::resolveSourceAttrInfo($sourceNode, (string) $tag);
        if ([] === $info['pairs']) {
            return $sourceNode->compileTimeDomAttributes;
        }
        $out = [];
        foreach ($info['pairs'] as $pair) {
            $out[$pair['qname']] = $pair['value'];
        }

        return $out;
    }

    /**
     * Open-tag attr suffix + NamedNodeMap pairs for the imported source node (#33362).
     *
     * Cross-document importNode runs after the destination loadXML, so
     * {@see JitDomLoadXMLUserScript::lastCompileTimeXml()} is the *dst* tree —
     * recover the source child/root markup from remembered literals instead.
     *
     * @return array{attrs: string, pairs: list<array{qname: string, value: string}>}
     */
    private static function resolveSourceAttrInfo(JITVariable $sourceNode, string $tag): array
    {
        $empty = ['attrs' => '', 'pairs' => []];
        if (null !== $sourceNode->compileTimeDomAttributes && [] !== $sourceNode->compileTimeDomAttributes) {
            $parts = [];
            $pairs = [];
            foreach ($sourceNode->compileTimeDomAttributes as $name => $value) {
                $name = (string) $name;
                $value = (string) $value;
                $parts[] = $name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8').'"';
                $pairs[] = ['qname' => $name, 'value' => $value];
            }
            $attrs = [] === $parts ? '' : ' '.implode(' ', $parts);

            return ['attrs' => $attrs, 'pairs' => $pairs];
        }

        $markup = self::resolveSourceElementMarkup($sourceNode, $tag);
        if (null === $markup) {
            return $empty;
        }
        $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($markup);
        if (null === $parsed) {
            return $empty;
        }
        $attrs = $parsed['attrs'];
        if ('' === trim($attrs)) {
            return $empty;
        }
        $open = '<'.$parsed['tag'].$attrs.'>';

        return [
            'attrs' => $attrs,
            'pairs' => DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv($open),
        ];
    }

    /**
     * Outer markup of the imported element from compile-time loadXML literals (#33362).
     */
    private static function resolveSourceElementMarkup(JITVariable $sourceNode, string $tag): ?string
    {
        $index = $sourceNode->compileTimeDomChildIndex;
        $candidates = [];
        $bound = $sourceNode->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::compileTimeXmlFor($sourceNode);
        if (null !== $bound) {
            $candidates[] = $bound;
        }
        // Destination loadXML is usually last — prefer every other remembered literal first.
        $exclude = JitDomLoadXMLUserScript::lastCompileTimeXml();
        $alt = JitDomLoadXMLUserScript::compileTimeXmlExcluding($exclude);
        if (null !== $alt) {
            $candidates[] = $alt;
        }
        if (null !== $exclude) {
            $candidates[] = $exclude;
        }
        $seen = [];
        foreach ($candidates as $xml) {
            if (isset($seen[$xml])) {
                continue;
            }
            $seen[$xml] = true;
            $stripped = preg_replace('/^\s*<\?xml[^?]*\?>\s*/i', '', trim($xml)) ?? trim($xml);
            if (null !== $index) {
                $chunks = DomParseSimpleXmlJitHelper::directChildMarkupChunks(
                    DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml)
                );
                if (isset($chunks[$index])) {
                    $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($chunks[$index]);
                    if (null !== $parsed
                        && ('' === $tag || strtolower($parsed['tag']) === strtolower($tag))
                    ) {
                        return $chunks[$index];
                    }
                }
                continue;
            }
            // documentElement / root import — attrs on the root open tag.
            $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($stripped);
            if (null !== $parsed
                && ('' === $tag || strtolower($parsed['tag']) === strtolower($tag))
            ) {
                return $stripped;
            }
        }

        return null;
    }

    /**
     * Compile-time $deep for user-script importNode (php-src default false).
     * Same shape as {@see JitDomCloneNode::compileTimeDeep} (#33097).
     */
    private static function compileTimeDeep(?JITVariable $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeString) {
            return '1' === $arg->compileTimeString || 'true' === strtolower($arg->compileTimeString);
        }

        return false;
    }

    /**
     * Root tag + child markup from a compile-time loadXML literal (#32350).
     *
     * @return array{tag: string, inner: string}|null
     */
    private static function parseCompileTimeXmlRoot(string $xml): ?array
    {
        $xml = ltrim($xml);
        if (str_starts_with($xml, '<?xml')) {
            $end = strpos($xml, '?>');
            if (false !== $end) {
                $xml = ltrim(substr($xml, $end + 2));
            }
        }
        if (1 === preg_match('/^<([A-Za-z_][\w:.-]*)\b[^>]*\/>/', $xml, $m)) {
            return ['tag' => $m[1], 'inner' => ''];
        }
        if (1 === preg_match('/^<([A-Za-z_][\w:.-]*)\b[^>]*>(.*)<\/\1\s*>/s', $xml, $m)) {
            return ['tag' => $m[1], 'inner' => $m[2]];
        }

        return null;
    }

    private static function storeElementInIdMap(
        Context $context,
        JITVariable $documentVar,
        string $idLit,
        Value $element
    ): void {
        $document = self::loadObjectArg($context, $documentVar);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($classId, VmDom::PROP_ELEMENT_ID_MAP)) {
            $objectType->defineProperty($classId, VmDom::PROP_ELEMENT_ID_MAP, JITVariable::TYPE_VALUE);
        }
        $mapVar = ObjectInstancePropertyLlvm::propertyFetchOrdinary(
            $objectType,
            $document,
            self::CLASS_DOCUMENT,
            VmDom::PROP_ELEMENT_ID_MAP,
            $classId
        );
        $ht = HashTableHelper::readHashtableFromValueBox($context, $mapVar);
        $idStr = $context->builder->load($context->constantStringFromString($idLit));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyObject'),
            $ht,
            $idStr,
            $element
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $propSlot = $objectType->propertySlotFor(
            $document,
            self::CLASS_DOCUMENT,
            VmDom::PROP_ELEMENT_ID_MAP
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $objectType->propertyStore($propSlot, $propVar, JITVariable::TYPE_VALUE);
        DomUserScriptElementCacheLlvm::store($context, $document, $idStr, $element);
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMDocument::importNode() expects object nodes');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }

    private static function loadBoolAsInt(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (null !== $arg->compileTimeLong) {
            return $i64->constInt(0 !== $arg->compileTimeLong ? 1 : 0, false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type || JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            $v = $context->helper->loadValue($arg);

            return $context->builder->zExt($v, $i64);
        }
        $valPtr = JitValueBox::valuePtrFromVariable($context, $arg);
        // No __value__readBool — bool payload is int8 at value[0] (#29109 / #21892).
        $boolByte = JitValueBox::readBoolByte($context, $valPtr);

        return $context->builder->zExt($boolByte, $i64);
    }

    private static function boxObjectResult(Context $context, Value $object): Value
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
}
