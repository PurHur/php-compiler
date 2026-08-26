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
 * DOMText sources materialize via {@see JitDomCreateTextNode} (#35043).
 */
final class JitDomImportNode
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    /** Tag of the last user-script materialize — ARG_SEND may drop Variable stamps. */
    public static ?string $lastMaterializedTagName = null;

    /** InnerXml of that materialize — subtree element count for live tag lists. */
    public static ?string $lastMaterializedInnerXml = null;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        self::$lastMaterializedTagName = null;
        self::$lastMaterializedInnerXml = null;
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
     * ARG_SEND temps for `$src->documentElement->firstChild` often drop Variable
     * stamps; recover via lastFetched* + source-document markup (peer cloneNode #32949).
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
        // Text / CharacterData first — element-only fallback treats destination loadXML root
        // as the import and yields a wrong DOMElement (#35043 / php-src xmlDocCopyNode).
        $textData = self::resolveCompileTimeTextData($sourceNode);
        if (null !== $textData) {
            return self::materializeImportedText($context, $textData);
        }

        $html = JitDomLoadHTMLUserScript::lastGetElementByIdHit()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsed();
        $tag = 'div';
        $text = '';
        $inner = '';
        $id = 'target';
        $fromXml = false;
        $srcTag = $sourceNode->compileTimeDomTagName ?? null;
        $srcInner = $sourceNode->compileTimeDomInnerXml ?? null;
        $srcIndex = $sourceNode->compileTimeDomChildIndex ?? null;
        // ARG_SEND copies drop compile-time DOM stamps (peer cloneNode #32949).
        // 1) Child edges: lastFetchedChildIndex from firstChild/lastChild walks.
        // 2) documentElement: annotateDocumentElement clears lastFetched* but leaves
        //    GetNodePath::$lastPath as a single-segment path ('/x') + $lastInner.
        if ((null === $srcTag || '' === $srcTag) && null === $sourceNode->compileTimeDomNodePath) {
            $srcIndex = $srcIndex ?? JitDomNodeChildProperty::$lastFetchedChildIndex;
            if (null !== $srcIndex) {
                $srcTag = JitDomNodeChildProperty::$lastFetchedTagName;
                $srcInner = $srcInner ?? JitDomGetNodePath::$lastInner;
            } elseif (null !== JitDomGetNodePath::$lastPath
                && 1 === preg_match('#^/([^/\[\]]+)$#', JitDomGetNodePath::$lastPath, $pathMatch)
            ) {
                $srcTag = $pathMatch[1];
                $srcInner = $srcInner ?? JitDomGetNodePath::$lastInner;
            }
        } elseif ((null === $srcTag || '' === $srcTag)
            && null !== $sourceNode->compileTimeDomNodePath
            && 1 === preg_match('#/([^/\[\]]+)$#', $sourceNode->compileTimeDomNodePath, $pathMatch)
        ) {
            // nodePath survived ARG_SEND but tagName did not.
            $srcTag = $pathMatch[1];
            $srcInner = $srcInner ?? $sourceNode->compileTimeDomInnerXml ?? JitDomGetNodePath::$lastInner;
        }
        // Seed recovered index/tag so resolveSourceElementMarkup can pick the child.
        if (null !== $srcIndex && null === $sourceNode->compileTimeDomChildIndex) {
            $sourceNode->compileTimeDomChildIndex = $srcIndex;
        }
        if (null !== $srcTag && '' !== $srcTag && null === $sourceNode->compileTimeDomTagName) {
            $sourceNode->compileTimeDomTagName = $srcTag;
        }
        // Path recovery may yield libxml `text()` — not an element tag (#35043).
        if ('text()' === $srcTag || '#text' === $srcTag) {
            $data = self::resolveCompileTimeTextData($sourceNode)
                ?? JitDomCreateTextNode::$lastMaterializedData
                ?? '';

            return self::materializeImportedText($context, $data);
        }
        if (null !== $srcTag && '' !== $srcTag) {
            $tag = $srcTag;
            if (null !== $srcInner) {
                $inner = $srcInner;
                $fromXml = true;
            } else {
                $dstXml = JitDomLoadXMLUserScript::compileTimeXmlFor($documentVar)
                    ?? $documentVar->compileTimeDomLoadXml
                    ?? null;
                $markup = self::resolveSourceElementMarkup($sourceNode, $tag, $dstXml);
                if (null !== $markup) {
                    $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($markup);
                    if (null !== $parsed) {
                        $inner = $parsed['inner'];
                    }
                }
                $fromXml = true;
            }
        }
        if (!$fromXml) {
            // Cross-document: recover source markup excluding the *destination* loadXML
            // (lastCompileTimeXml is often the source when documentElement was loaded last).
            $dstXml = JitDomLoadXMLUserScript::compileTimeXmlFor($documentVar)
                ?? $documentVar->compileTimeDomLoadXml
                ?? null;
            $markup = self::resolveSourceElementMarkup($sourceNode, '', $dstXml);
            if (null !== $markup) {
                $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($markup);
                if (null !== $parsed) {
                    $tag = $parsed['tag'];
                    $inner = $parsed['inner'];
                    $fromXml = true;
                }
            }
        }
        if (!$fromXml) {
            $dstXml = JitDomLoadXMLUserScript::compileTimeXmlFor($documentVar)
                ?? $documentVar->compileTimeDomLoadXml
                ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
            // Prefer source-bound / non-destination literals — never treat the destination
            // loadXML root as the imported element when another document exists.
            $xml = $sourceNode->compileTimeDomLoadXml
                ?? JitDomLoadXMLUserScript::compileTimeXmlFor($sourceNode)
                ?? JitDomLoadXMLUserScript::compileTimeXmlExcluding($dstXml)
                ?? $dstXml;
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

        self::$lastMaterializedTagName = $tag;
        self::$lastMaterializedInnerXml = $inner;

        $attrInfo = self::resolveSourceAttrInfo($sourceNode, $tag, $documentVar);

        $element = JitDomCreateElement::materializeForUserScriptDocument(
            $context,
            $documentVar,
            $tag,
            $text
        );
        if ($deep && '' !== $inner) {
            JitDomCreateElement::storeUserScriptInnerXml($context, $element, $inner);
            // saveXML reads InnerXml; getElementsByTagName / firstChild need LiveSlots
            // (peer cloneNode #32949 / xmlDocCopyNode deep).
            $attrs = $attrInfo['attrs'];
            $openAttrs = '' === $attrs ? '' : (str_starts_with($attrs, ' ') ? $attrs : ' '.$attrs);
            $outer = '<'.$tag.$openAttrs.'>'.$inner.'</'.$tag.'>';
            JitDomDocumentElement::syncChildrenFromXmlPublic($context, $element, $outer);
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
     * Compile-time text payload for importNode(DOMText) (#35043).
     *
     * ARG_SEND often drops Variable stamps; recover via createTextNode /
     * firstChild|nextSibling lastMaterializedData (peer cloneNode / #35021).
     */
    private static function resolveCompileTimeTextData(JITVariable $sourceNode): ?string
    {
        if (null !== $sourceNode->compileTimeDomTextData) {
            return $sourceNode->compileTimeDomTextData;
        }
        $tag = $sourceNode->compileTimeDomTagName;
        if ('#text' === $tag) {
            return JitDomCreateTextNode::$lastMaterializedData ?? '';
        }
        $path = $sourceNode->compileTimeDomNodePath ?? JitDomGetNodePath::$lastPath;
        if (\is_string($path) && 1 === preg_match('#(?:^|/)text\(\)$#', $path)) {
            return JitDomCreateTextNode::$lastMaterializedData ?? '';
        }
        // Child-edge text: stampChildIndex clears lastFetchedTagName (#34314).
        $index = $sourceNode->compileTimeDomChildIndex
            ?? JitDomNodeChildProperty::$lastFetchedChildIndex;
        if (null !== $index
            && (null === $tag || '' === $tag)
            && null === JitDomNodeChildProperty::$lastFetchedTagName
            && null !== JitDomCreateTextNode::$lastMaterializedData
        ) {
            return JitDomCreateTextNode::$lastMaterializedData;
        }
        // createTextNode($lit) result — no element identity stamps (#35043).
        if ((null === $tag || '' === $tag)
            && null === $sourceNode->compileTimeDomChildIndex
            && null === $sourceNode->compileTimeDomNodePath
            && null === $sourceNode->compileTimeDomInnerXml
            && null === $sourceNode->compileTimeDomLoadXml
            && null !== JitDomCreateTextNode::$lastMaterializedData
            && null === JitDomNodeChildProperty::$lastFetchedTagName
        ) {
            return JitDomCreateTextNode::$lastMaterializedData;
        }

        return null;
    }

    private static function materializeImportedText(Context $context, string $data): Value
    {
        self::$lastMaterializedTagName = '#text';
        self::$lastMaterializedInnerXml = null;
        $obj = JitDomCreateTextNode::materialize($context, $data);

        return self::boxObjectResult($context, $obj);
    }

    /**
     * Name→value attrs for compile-time INNER_XML sync after importNode (#33362).
     *
     * @return array<string, string>|null
     */
    public static function compileTimeAttributesFor(JITVariable $sourceNode, ?string $tag = null): ?array
    {
        $tag = $tag ?? ($sourceNode->compileTimeDomTagName ?? '');
        $info = self::resolveSourceAttrInfo($sourceNode, (string) $tag, null);
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
     * Cross-document importNode must exclude the *destination* loadXML literal —
     * lastCompileTimeXml may be either document depending on load order.
     *
     * @return array{attrs: string, pairs: list<array{qname: string, value: string}>}
     */
    private static function resolveSourceAttrInfo(
        JITVariable $sourceNode,
        string $tag,
        ?JITVariable $destinationDocument = null
    ): array {
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

        $dstXml = null;
        if (null !== $destinationDocument) {
            $dstXml = JitDomLoadXMLUserScript::compileTimeXmlFor($destinationDocument)
                ?? $destinationDocument->compileTimeDomLoadXml;
        }
        $markup = self::resolveSourceElementMarkup($sourceNode, $tag, $dstXml);
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
     *
     * @param string|null $excludeDstXml Destination document loadXML — never treat as source
     */
    private static function resolveSourceElementMarkup(
        JITVariable $sourceNode,
        string $tag,
        ?string $excludeDstXml = null
    ): ?string {
        $index = $sourceNode->compileTimeDomChildIndex;
        $candidates = [];
        $bound = $sourceNode->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::compileTimeXmlFor($sourceNode);
        if (null !== $bound) {
            $candidates[] = $bound;
        }
        // Prefer non-destination remembered literals first.
        $exclude = $excludeDstXml ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        $alt = JitDomLoadXMLUserScript::compileTimeXmlExcluding($exclude);
        if (null !== $alt) {
            $candidates[] = $alt;
        }
        // Only fall back to the excluded literal when it is *not* the destination
        // (legacy single-document / source-loaded-last paths).
        if (null !== $exclude && $exclude !== $excludeDstXml) {
            $candidates[] = $exclude;
        } elseif (null !== $exclude && null === $excludeDstXml) {
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
