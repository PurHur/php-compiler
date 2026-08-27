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
 * LLVM lowering for DOMDocument::importNode() (#19212, #32350, #33097, #33362, #35118).
 *
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, importNode) → xmlDocCopyNode.
 * Thin-standalone AOT cannot return NestedJIT object pointers (property fetch
 * aborts; contrast adoptNode #29853 which reuses the caller-side node). Materialize
 * a user-script DOMElement instead — tag/inner XML from compile-time loadXML
 * (#32350) or loadHTML getElementById (#19212). `$deep` must gate InnerXml (#33097).
 * Attributes are always copied (xmlDocCopyNode); #33097 only gated children (#33362).
 * Attr / leaf sources materialize as stand-ins (#35043 / #35098 / #35118) — never the
 * destination loadXML root via lastPath poisoning.
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
     *
     * Character-data / PI leaf sources must materialize via create* stand-ins —
     * {@see JitDomCreateTextNode} / {@see JitDomCreateComment} /
     * {@see JitDomCreateCDATASection} / {@see JitDomCreateProcessingInstruction}.
     * loadXML firstChild stamps {@see JITVariable::$compileTimeDomTextData} for all
     * CharacterData kinds; treating that as text alone materializes `#text` (#35043 /
     * #35098 / peer cloneNode). Element-only fallback would return the destination
     * root tag (e.g. `r`) as a DOMElement.
     *
     * DOMAttr / Dom\Attr sources must materialize via
     * {@see JitDomAttributeNodeNS::materializeAttrFromLiterals} (#35118). getAttributeNode
     * leaves {@see JitDomAttrRename::lastFetchedKey} while `$src->documentElement` poisons
     * {@see JitDomGetNodePath::$lastPath} — element recovery would copy the dest root.
     */
    private static function invokeUserScriptMaterialize(
        Context $context,
        JITVariable $documentVar,
        JITVariable $sourceNode,
        bool $deep
    ): Value {
        $leaf = self::resolveSourceLeafSpec($sourceNode, $documentVar);
        if (null !== $leaf) {
            self::$lastMaterializedInnerXml = '';
            if ('comment' === $leaf['kind']) {
                self::$lastMaterializedTagName = '#comment';
                $object = JitDomCreateComment::materialize($context, $leaf['data']);
            } elseif ('cdata' === $leaf['kind']) {
                self::$lastMaterializedTagName = '#cdata-section';
                $object = JitDomCreateCDATASection::materialize($context, $leaf['data']);
            } elseif ('pi' === $leaf['kind']) {
                self::$lastMaterializedTagName = JitDomCreateProcessingInstruction::TAG_KIND;
                $object = JitDomCreateProcessingInstruction::materialize(
                    $context,
                    $leaf['data'],
                    $leaf['content'] ?? ''
                );
            } else {
                self::$lastMaterializedTagName = '#text';
                $object = JitDomCreateTextNode::materialize($context, $leaf['data']);
            }

            return self::boxObjectResult($context, $object);
        }

        $attrSpec = self::resolveSourceAttrSpec($sourceNode);
        if (null !== $attrSpec) {
            return self::materializeImportedAttr($context, $attrSpec);
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
            if (self::importedAttrsStampHtmlIdBearing($sourceNode, $attrInfo['pairs'], $fromXml)) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral('', 'id', true);
                DomUserScriptAttributeCacheLlvm::storeIdBearingGlobal($context, true);
            }
        }
        if (!$fromXml) {
            self::storeElementInIdMap($context, $documentVar, $id, $element);
        }

        return self::boxObjectResult($context, $element);
    }

    /**
     * Attr import source: getAttributeNode / createAttribute orphan (#35118).
     *
     * Element identity stamps on the Variable (documentElement / firstChild) win —
     * ARG_SEND may drop them, but then lastPath recovery in the element path applies.
     * Prefer Attr only when the Variable itself carries no element/leaf stamps.
     *
     * @return null|array{namespace: string, qname: string, local: string, value: string}
     */
    private static function resolveSourceAttrSpec(JITVariable $sourceNode): ?array
    {
        if (null !== $sourceNode->compileTimeDomTagName
            || null !== $sourceNode->compileTimeDomChildIndex
            || null !== $sourceNode->compileTimeDomNodePath
            || null !== $sourceNode->compileTimeDomTextData
            || null !== $sourceNode->compileTimeDomInnerXml
        ) {
            return null;
        }

        // createAttribute orphan — rememberOrphan; getAttributeNode clears orphan flag.
        if (JitDomAttrRename::lastAttrIsOrphan()) {
            $ns = DomUserScriptAttributeCacheLlvm::lastCreateNamespace();
            $local = DomUserScriptAttributeCacheLlvm::lastCreateLocalName();
            $qname = DomUserScriptAttributeCacheLlvm::lastCreateQualifiedName();
            if (null !== $ns && null !== $local && null !== $qname) {
                $value = DomUserScriptAttributeCacheLlvm::literalValue($ns, $local) ?? '';

                return [
                    'namespace' => $ns,
                    'qname' => $qname,
                    'local' => $local,
                    'value' => $value,
                ];
            }
        }

        $key = JitDomAttrRename::lastFetchedKey();
        if (null === $key) {
            return null;
        }
        [$ns, $local] = $key;
        $value = DomUserScriptAttributeCacheLlvm::literalValue($ns, $local);
        $qname = $local;
        // Prefer documentElement-bound XML — lastCompileTimeXml may be another document
        // after a second loadXML (#35131 / peer getAttributeNodeNS).
        $xml = $sourceNode->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::compileTimeXmlFor($sourceNode)
            ?? JitDomGetNodePath::$lastDocumentElementXml
            ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null !== $xml) {
            foreach (DomParseSimpleXmlJitHelper::rootAttributesArgv($xml) as $pair) {
                $pairQ = $pair['qname'];
                $pos = strpos($pairQ, ':');
                $pairLocal = false === $pos ? $pairQ : substr($pairQ, $pos + 1);
                $pairNs = $pair['namespace'] ?? '';
                if ($pairLocal === $local && $pairNs === $ns) {
                    $qname = $pairQ;
                    if (null === $value) {
                        $value = $pair['value'];
                    }
                    break;
                }
                if ('' === $ns && ($local === $pairQ || $local === $pairLocal)) {
                    $qname = $pairQ;
                    if (null === $value) {
                        $value = $pair['value'];
                    }
                    break;
                }
            }
        }
        $value ??= '';

        return [
            'namespace' => $ns,
            'qname' => $qname,
            'local' => $local,
            'value' => $value,
        ];
    }

    /**
     * xmlDocCopyNode of XML_ATTRIBUTE_NODE — orphan Attr on the destination document (#35118).
     *
     * @param array{namespace: string, qname: string, local: string, value: string} $spec
     */
    private static function materializeImportedAttr(Context $context, array $spec): Value
    {
        self::$lastMaterializedTagName = $spec['qname'];
        self::$lastMaterializedInnerXml = '';
        $living = null !== JitDomLoadXMLUserScript::lastDocumentClass()
            && str_starts_with((string) JitDomLoadXMLUserScript::lastDocumentClass(), 'Dom\\');
        $className = $living
            ? JitDomAttributeNodeNS::CLASS_LIVING_ATTR
            : 'DOMAttr';
        $object = JitDomAttributeNodeNS::materializeAttrFromLiterals(
            $context,
            $spec['namespace'],
            $spec['qname'],
            $spec['value'],
            $className,
            $living
        );
        // setAttributeNode user-script path keys off lastCreate* (#33570 / #35118).
        DomUserScriptAttributeCacheLlvm::rememberCreate($spec['namespace'], $spec['qname']);
        DomUserScriptAttributeCacheLlvm::setLiteralValue(
            $spec['namespace'],
            $spec['local'],
            $spec['value']
        );
        JitDomAttrRename::rememberOrphan();

        return self::boxObjectResult($context, $object);
    }

    /**
     * Leaf import source: text / comment / CDATA / PI (#35043 / #35098).
     *
     * {@see JITVariable::$compileTimeDomTextData} is shared by CharacterData kinds on
     * firstChild temps — resolve kind from tag / child index before materializing.
     * Prefer stamps on the source Variable over cross-document child-list lookup
     * (lastCompileTimeXml may be a different loadXML).
     * Empty string is a valid text/comment/CDATA body.
     *
     * @return null|array{kind: 'text'|'comment'|'cdata'|'pi', data: string, content?: string}
     */
    private static function resolveSourceLeafSpec(
        JITVariable $sourceNode,
        JITVariable $documentVar
    ): ?array {
        $tag = $sourceNode->compileTimeDomTagName;
        $textData = $sourceNode->compileTimeDomTextData;

        // Explicit leaf discriminators from firstChild/sibling stamps (#35098).
        if ('#comment' === $tag) {
            return ['kind' => 'comment', 'data' => $textData ?? ''];
        }
        if ('#cdata-section' === $tag) {
            return ['kind' => 'cdata', 'data' => $textData ?? ''];
        }
        if (JitDomCreateProcessingInstruction::TAG_KIND === $tag) {
            $target = $sourceNode->compileTimeDomAttributes['target']
                ?? JitDomNodeChildProperty::$lastFetchedPiTarget
                ?? '';

            return [
                'kind' => 'pi',
                'data' => $target,
                'content' => $textData ?? '',
            ];
        }
        if ('#text' === $tag) {
            return [
                'kind' => 'text',
                'data' => $textData ?? JitDomCreateTextNode::$lastMaterializedData ?? '',
            ];
        }
        // Element tag — not a character-data leaf.
        if (null !== $tag && '' !== $tag) {
            return null;
        }

        // Indexed child kind when ARG_SEND dropped the tag stamp.
        $index = $sourceNode->compileTimeDomChildIndex
            ?? JitDomNodeChildProperty::$lastFetchedChildIndex;
        if (null !== $index) {
            $dstXml = JitDomLoadXMLUserScript::compileTimeXmlFor($documentVar)
                ?? $documentVar->compileTimeDomLoadXml
                ?? null;
            $nodes = self::resolveSourceChildNodes($sourceNode, $dstXml);
            if (isset($nodes[$index])) {
                $node = $nodes[$index];
                $kind = $node['kind'] ?? '';
                if ('pi' === $kind) {
                    return [
                        'kind' => 'pi',
                        'data' => $node['data'],
                        'content' => $node['content'] ?? '',
                    ];
                }
                if ('text' === $kind || 'comment' === $kind || 'cdata' === $kind) {
                    return ['kind' => $kind, 'data' => $node['data']];
                }

                // Indexed element / other — not a character-data leaf.
                return null;
            }
        }

        if (null !== $textData) {
            // No index / tag: CharacterData stamp without kind — text (#35043 createTextNode).
            return ['kind' => 'text', 'data' => $textData];
        }

        // Detached createTextNode: ARG_SEND may drop TextData but leave lastMaterializedData
        // and no element child-fetch stamp (peer splitText #32362).
        if (null === $sourceNode->compileTimeDomNodePath
            && null === JitDomNodeChildProperty::$lastFetchedTagName
            && null !== JitDomCreateTextNode::$lastMaterializedData
        ) {
            return ['kind' => 'text', 'data' => JitDomCreateTextNode::$lastMaterializedData];
        }

        return null;
    }

    /**
     * Direct children of the import *source* document (exclude destination loadXML).
     *
     * @return list<array{kind: string, data: string, content?: string, inner?: string, open?: string}>
     */
    private static function resolveSourceChildNodes(
        JITVariable $sourceNode,
        ?string $excludeDstXml
    ): array {
        $candidates = [];
        $bound = $sourceNode->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::compileTimeXmlFor($sourceNode);
        if (null !== $bound) {
            $candidates[] = $bound;
        }
        $alt = JitDomLoadXMLUserScript::compileTimeXmlExcluding(
            $excludeDstXml ?? JitDomLoadXMLUserScript::lastCompileTimeXml()
        );
        if (null !== $alt) {
            $candidates[] = $alt;
        }
        $last = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null !== $last && $last !== $excludeDstXml) {
            $candidates[] = $last;
        }
        $seen = [];
        foreach ($candidates as $xml) {
            if (isset($seen[$xml])) {
                continue;
            }
            $seen[$xml] = true;
            $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
            if ([] !== $nodes) {
                return $nodes;
            }
        }

        return [];
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
            return self::resolveSourceAttrInfoFromHtmlHit($tag) ?? $empty;
        }
        $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($markup);
        if (null === $parsed) {
            return self::resolveSourceAttrInfoFromHtmlHit($tag) ?? $empty;
        }
        $attrs = $parsed['attrs'];
        if ('' === trim($attrs)) {
            return self::resolveSourceAttrInfoFromHtmlHit($tag) ?? $empty;
        }
        $open = '<'.$parsed['tag'].$attrs.'>';

        return [
            'attrs' => $attrs,
            'pairs' => DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv($open),
        ];
    }

    /**
     * loadHTML / getElementById sources have no compile-time XML markup — recover id
     * from the remembered HTML parse so importNode copies attrs (xmlDocCopyNode; #19212).
     *
     * @return null|array{attrs: string, pairs: list<array{qname: string, value: string}>}
     */
    private static function resolveSourceAttrInfoFromHtmlHit(string $tag): ?array
    {
        $html = JitDomLoadHTMLUserScript::lastGetElementByIdHit()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsed();
        if (null === $html) {
            return null;
        }
        $hitTag = (string) ($html['tag'] ?? '');
        if ('' !== $tag && '' !== $hitTag && $tag !== $hitTag) {
            return null;
        }
        $id = (string) ($html['id'] ?? '');
        if ('' === $id) {
            return null;
        }
        $escaped = htmlspecialchars($id, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return [
            'attrs' => ' id="'.$escaped.'"',
            'pairs' => [['qname' => 'id', 'value' => $id]],
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

    /**
     * Whether imported id= should stamp XML_ATTRIBUTE_ID for isId() (#23514 / #20830).
     *
     * @param list<array{qname: string, value: string}> $pairs
     */
    private static function importedAttrsStampHtmlIdBearing(
        JITVariable $sourceNode,
        array $pairs,
        bool $fromXml
    ): bool {
        $hasId = false;
        foreach ($pairs as $pair) {
            if ('id' === $pair['qname'] && '' !== $pair['value']) {
                $hasId = true;
                break;
            }
        }
        if (!$hasId) {
            return false;
        }
        if (!$fromXml) {
            return true;
        }
        // Plain XML id into HTML must not promote until remove+set (#23514).
        if (null !== $sourceNode->compileTimeDomLoadXml
            || null !== JitDomLoadXMLUserScript::compileTimeXmlFor($sourceNode)
        ) {
            return false;
        }

        return null !== JitDomLoadHTMLUserScript::lastGetElementByIdHit()
            || null !== JitDomLoadHTMLUserScript::lastCompileTimeParsed();
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
