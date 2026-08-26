<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument / Dom\XMLDocument::$documentElement
 * (#18478, #19455, #23251, #27108, #32736).
 */
final class JitDomDocumentElement
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_XML_DOCUMENT = 'Dom\\XMLDocument';

    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_DOCUMENT_ELEMENT = 'documentElement';

    public static function isDomDocumentElement(string $classLc, string $propLc): bool
    {
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        $propLc = strtolower($propLc);

        return ('domdocument' === $classLc || 'dom\\xmldocument' === $classLc)
            && 'documentelement' === $propLc;
    }

    public static function fetch(
        Object_ $objectType,
        Value $obj,
        ?JITVariable $documentVar = null,
        string $className = self::CLASS_DOCUMENT
    ): JITVariable {
        $context = $objectType->jitContext();
        if (!JitDomDocumentMethodKernel::shouldUse($context)) {
            return $objectType->propertyFetchOrdinary(
                $obj,
                self::CLASS_DOCUMENT,
                self::PROP_DOCUMENT_ELEMENT
            );
        }

        // Always read the TYPE_OBJECT slot on the *receiver* document class (loadXML /
        // loadHTML / appendChild setRoot). lastDocumentClass is process-global compile
        // state — using it for an unrelated/new document read firstChild as object (#32736).
        // An empty document stores a null pointer — {@see JitUnlikeCompare} treats
        // TYPE_OBJECT+nullptr as PHP null for === / !== (#32736).
        $docClass = $className;
        $docClassId = $objectType->lookup($docClass);
        if (!$objectType->hasProperty($docClassId, self::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, self::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }

        $result = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            $docClass,
            self::PROP_DOCUMENT_ELEMENT,
            $docClassId
        );
        // So `$doc->documentElement->attributes` / `->childNodes` resolve DOMElement (#33082 / #33099).
        if (JITVariable::TYPE_OBJECT === $result->type) {
            $ptr = JITVariable::KIND_VALUE === $result->kind
                ? $result->value
                : $context->builder->load($result->value);
            $isNull = $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $ptr,
                $context->getTypeFromString('__object__*')->constNull()
            );
            $fn = $context->builder->getInsertBlock()->getParent();
            assert($fn instanceof \PHPLLVM\Value\Function_);
            $setEl = $fn->appendBasicBlock('dom_de_set_el_type');
            $done = $fn->appendBasicBlock('dom_de_done_type');
            $context->builder->branchIf($isNull, $done, $setEl);
            $context->builder->positionAtEnd($setEl);
            $result->classUserType = self::CLASS_ELEMENT;
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);
        }
        if (null !== JitDomLoadXMLUserScript::lastCompileTimeXml()
            || null !== JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml()
            || (null !== $documentVar && null !== $documentVar->compileTimeDomLoadXml)
        ) {
            JitDomGetNodePath::annotateDocumentElement($result, $documentVar);
        }

        return $result;
    }

    /**
     * Seed firstChild/lastChild/parentNode/sibling slots from compile-time XML (#19455, #23251).
     *
     * Element children are required so held references survive textContent writes (detach).
     * Sibling links must use {@see CLASS_ELEMENT} slots — same as
     * {@see JitDomAppendChildLiveSlots} (#27476 / #28672). Writing DOMNode
     * next/previous into a DOMElement allocation corrupts layout; LiveSlots then
     * sees null next on same-parent move and clears firstChild → AOT segfault.
     */
    public static function syncChildrenFromXmlPublic(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        string $xml,
        string $parentPath = '',
        ?Value $ownerDocument = null
    ): void {
        if ('' === $parentPath) {
            $parentPath = '/'.DomParseSimpleXmlJitHelper::rootTagArgv($xml);
        }
        // Seed descendant attr NS resolution from the root open-tag xmlns (#34618).
        $rootNs = [];
        if (preg_match('/<([a-zA-Z_][\w:.-]*)((?:\s[^>]*)?)\/?>/', $xml, $rootOpen)) {
            $rootNs = DomParseSimpleXmlJitHelper::xmlnsDeclsFromOpenTagArgv($rootOpen[0]);
        }
        self::syncChildrenFromXml($context, $element, $xml, $parentPath, $ownerDocument, $rootNs);
    }

    /**
     * @param array<string, string> $inScopeNs prefix → URI inherited from ancestors
     */
    private static function syncChildrenFromXml(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        string $xml,
        string $parentPath,
        ?Value $ownerDocument = null,
        array $inScopeNs = []
    ): void {
        // Include blank text / comments so childNodes->length matches Zend (#27260).
        $children = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        if ([] === $children) {
            // Empty elements still need a live NodeList — skipping left
            // DOMNode::$childNodes unset and ->length SIGSEGVd on AOT.
            self::storeChildNodesLength($context, $element, 0);
            // Shallow cloneNode / empty markup must read firstChild as null (#32949).
            self::clearFirstLast($context, $element);
            self::clearElementNav($context, $element);

            return;
        }

        $objectType = $context->type->object;
        $prev = null;
        $prevElement = null;
        $first = null;
        $second = null;
        $last = null;
        $firstElement = null;
        $lastElement = null;
        $elementCount = 0;
        foreach ($children as $idx => $node) {
            $child = match ($node['kind']) {
                'comment' => JitDomCreateComment::materialize($context, $node['data']),
                'pi' => JitDomCreateProcessingInstruction::materialize(
                    $context,
                    $node['data'],
                    $node['content'] ?? ''
                ),
                'text' => JitDomCreateTextNode::materialize($context, $node['data']),
                'cdata' => JitDomCreateCDATASection::materialize($context, $node['data']),
                // Seed textContent/INNER_XML/attrs + NS props like createElementNS (#33014 / #34924).
                default => self::materializeElementChild($context, $node, $inScopeNs),
            };
            $segment = DomParseSimpleXmlJitHelper::nodePathSegmentArgv($children, $idx);
            $childScope = $inScopeNs;
            if ('element' === $node['kind'] && isset($node['open']) && \is_string($node['open'])) {
                $childScope = DomParseSimpleXmlJitHelper::xmlnsDeclsFromOpenTagArgv($node['open']) + $inScopeNs;
            }
            if ('element' === $node['kind'] && null !== $segment && '' !== $segment) {
                $childPath = rtrim($parentPath, '/').'/'.$segment;
                JitDomGetNodePath::storeOn($context, $child, self::CLASS_ELEMENT, $childPath);
                $inner = $node['inner'] ?? '';
                if ('' !== $inner) {
                    $openAttrs = '';
                    if (isset($node['open']) && \is_string($node['open'])) {
                        $openAttrs = DomParseSimpleXmlJitHelper::attrSuffixFromOpenTagArgv($node['open']);
                    }
                    $outer = '<'.$node['data'].$openAttrs.'>'.$inner.'</'.$node['data'].'>';
                    self::syncChildrenFromXml($context, $child, $outer, $childPath, $ownerDocument, $childScope);
                }
            }
            if ('element' === $node['kind'] && isset($node['open']) && \is_string($node['open'])) {
                // Inherit ancestor xmlns so prefixed attrs keep URI (xmlHasNsProp; #34618).
                JitDomCreateElement::storeAttributesPresence(
                    $context,
                    $child,
                    DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv($node['open'], $inScopeNs)
                );
                // DTD ID / xml:id → live tree node in document id map (#34696).
                if (null !== $ownerDocument) {
                    JitDomLoadXMLUserScript::registerLiveElementIdFromOpenTag(
                        $context,
                        $ownerDocument,
                        $child,
                        $node['data'],
                        $node['open']
                    );
                }
            }
            self::ensureLinkProps($context);
            $parentJit = new JITVariable(
                $context,
                JITVariable::TYPE_OBJECT,
                JITVariable::KIND_VALUE,
                $element
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($child, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
                $parentJit,
                JITVariable::TYPE_VALUE
            );
            // Seed ownerDocument for Wrong Document checks / $node->ownerDocument (#33937).
            if (null !== $ownerDocument) {
                $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
                if (!$objectType->hasProperty($elementClassId, VmDom::PROP_OWNER_DOCUMENT)) {
                    $objectType->defineProperty(
                        $elementClassId,
                        VmDom::PROP_OWNER_DOCUMENT,
                        JITVariable::TYPE_VALUE
                    );
                }
                $docJit = new JITVariable(
                    $context,
                    JITVariable::TYPE_OBJECT,
                    JITVariable::KIND_VALUE,
                    $ownerDocument
                );
                $objectType->propertyStore(
                    $objectType->propertySlotFor($child, self::CLASS_ELEMENT, VmDom::PROP_OWNER_DOCUMENT),
                    $docJit,
                    JITVariable::TYPE_VALUE
                );
            }
            if (null !== $prev) {
                $childJit = new JITVariable(
                    $context,
                    JITVariable::TYPE_OBJECT,
                    JITVariable::KIND_VALUE,
                    $child
                );
                $prevJit = new JITVariable(
                    $context,
                    JITVariable::TYPE_OBJECT,
                    JITVariable::KIND_VALUE,
                    $prev
                );
                // DOMElement — peer JitDomAppendChildLiveSlots / createElement layout (#28672).
                $objectType->propertyStore(
                    $objectType->propertySlotFor($prev, self::CLASS_ELEMENT, VmDom::PROP_NEXT_SIBLING),
                    $childJit,
                    JITVariable::TYPE_VALUE
                );
                $objectType->propertyStore(
                    $objectType->propertySlotFor($child, self::CLASS_ELEMENT, VmDom::PROP_PREVIOUS_SIBLING),
                    $prevJit,
                    JITVariable::TYPE_VALUE
                );
            }
            if ('element' === $node['kind']) {
                if (null !== $prevElement) {
                    self::linkElementSiblings($context, $prevElement, $child);
                }
                if (null === $firstElement) {
                    $firstElement = $child;
                }
                $lastElement = $child;
                ++$elementCount;
                $prevElement = $child;
            }
            if (null === $first) {
                $first = $child;
            } elseif (null === $second) {
                // __phpcItem1 must be the second child, not last (#32784).
                $second = $child;
            }
            $last = $child;
            $prev = $child;
        }
        if (null !== $first && null !== $last) {
            self::storeFirstLast($context, $element, $first, $last);
        }
        self::storeChildNodesLength($context, $element, \count($children), $first, $second);
        if ($elementCount > 0 && null !== $firstElement && null !== $lastElement) {
            self::storeElementNav($context, $element, $firstElement, $lastElement, $elementCount);
        } else {
            self::clearElementNav($context, $element);
        }
    }

    /**
     * Materialize a loadXML element child with textContent / INNER_XML / attr suffix (#33014).
     *
     * {@see materializeElementFromLiteral} alone left hollow firstChild slots so
     * textContent/saveXML/getElementById diverged from Zend after setIdAttribute.
     *
     * Prefixed / default-NS children must use {@see JitDomCreateElementNS} so
     * prefix/localName/namespaceURI slots exist — non-NS allocate left
     * namespaceURI reads SIGSEGVing (#34924).
     *
     * @param array{kind: string, data: string, inner?: string, open?: string} $node
     * @param array<string, string>                                            $inScopeNs prefix → URI ('' = default)
     */
    private static function materializeElementChild(
        \PHPCompiler\JIT\Context $context,
        array $node,
        array $inScopeNs = []
    ): Value {
        $tag = $node['data'];
        $inner = $node['inner'] ?? '';
        $text = DomParseSimpleXmlJitHelper::textContentFromInnerXmlArgv($inner);
        $open = (isset($node['open']) && \is_string($node['open'])) ? $node['open'] : '';
        $child = self::materializeElementFromXmlTag($context, $tag, $text, $open, $inScopeNs);
        JitDomCreateElement::storeUserScriptInnerXml($context, $child, $inner);
        if ('' !== $open) {
            JitDomCreateElement::storeUserScriptXmlnsAttr(
                $context,
                $child,
                DomParseSimpleXmlJitHelper::attrSuffixFromOpenTagArgv($open)
            );
        }

        return $child;
    }

    /**
     * loadXML / syncChildren element allocate with NS props when QName resolves (#34924).
     *
     * @param array<string, string> $inScopeNs prefix → URI ('' = default xmlns)
     */
    public static function materializeElementFromXmlTag(
        \PHPCompiler\JIT\Context $context,
        string $tag,
        string $text,
        string $openTag = '',
        array $inScopeNs = []
    ): Value {
        $scope = $inScopeNs;
        if ('' !== $openTag) {
            $scope = DomParseSimpleXmlJitHelper::xmlnsDeclsFromOpenTagArgv($openTag) + $scope;
        }
        $colon = strpos($tag, ':');
        $prefix = false === $colon ? '' : substr($tag, 0, $colon);
        if ('' !== $prefix && isset($scope[$prefix])) {
            return JitDomCreateElementNS::materializeElementNSFromLiterals(
                $context,
                $scope[$prefix],
                $tag,
                $text
            );
        }
        // Default xmlns (including xmlns="") — Zend sets namespaceURI accordingly.
        if ('' === $prefix && \array_key_exists('', $scope)) {
            return JitDomCreateElementNS::materializeElementNSFromLiterals(
                $context,
                $scope[''],
                $tag,
                $text
            );
        }

        return JitDomCreateElement::materializeElementWithTextContent($context, $tag, $text);
    }

    /**
     * Attach a DOMNodeList with the given length for user-script childNodes (#23251).
     *
     * Optional first/second pin {@see VmDom::PROP_CHILD_NODES_OWNER} + __phpcItemN so
     * item(0)/item(1) work before any LiveSlots rewrite (#28672 / #27410 / #32784).
     * `$item1` is the **second** child (index 1), never lastChild when length > 2.
     */
    public static function storeChildNodesLength(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        int $length,
        ?Value $item0 = null,
        ?Value $item1 = null,
        ?string $slotClass = null
    ): void {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        $docClassId = $objectType->lookup('DOMDocument');
        $listClassId = $objectType->lookup('DOMNodeList');
        // VALUE on DOMElement — peer first/last/sibling LiveSlots layout (#27476 / #28672).
        // Writing DOMNode::childNodes indices into a DOMElement allocation is OOB (#24973).
        // Document parents use DOMDocument layout (#34160).
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }
        // Keep DOMNode declared for Document / Fragment receivers.
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($nodeClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }
        if (null === $slotClass) {
            $slotClass = self::CLASS_ELEMENT;
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }
        if (!$objectType->hasProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER)) {
            $objectType->defineProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
        }
        foreach (['__phpcItem0', '__phpcItem1'] as $prop) {
            if (!$objectType->hasProperty($listClassId, $prop)) {
                $objectType->defineProperty($listClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $lengthVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($length, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $lengthVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        $ownerJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $element
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );
        if (null !== $item0) {
            $i0 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item0);
            $objectType->propertyStore(
                $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem0'),
                $i0,
                JITVariable::TYPE_VALUE
            );
        }
        if (null !== $item1) {
            $i1 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item1);
            $objectType->propertyStore(
                $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem1'),
                $i1,
                JITVariable::TYPE_VALUE
            );
        }
        $listJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $list
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, $slotClass, VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
    }

    private static function ensureLinkProps(\PHPCompiler\JIT\Context $context): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        // Parent edges stay on DOMNode (property fetch / LiveSlots loadChildEdge).
        // DOMElement also declares first/last — createElement layout (#27476 / #28672).
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        // Sibling + parentNode on DOMElement — createElement / LiveSlots layout (#27476, #28672).
        foreach ([
            VmDom::PROP_NEXT_SIBLING,
            VmDom::PROP_PREVIOUS_SIBLING,
            VmDom::PROP_PARENT_NODE,
        ] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
    }

    private static function linkSingleChild(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        Value $child
    ): void {
        self::ensureLinkProps($context);
        self::storeFirstLast($context, $element, $child, $child);
    }

    private static function storeFirstLast(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        Value $first,
        Value $last
    ): void {
        $objectType = $context->type->object;
        $firstJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $first
        );
        $lastJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $last
        );
        foreach (
            [
                [VmDom::PROP_FIRST_CHILD, $firstJit],
                [VmDom::PROP_LAST_CHILD, $lastJit],
            ] as [$prop, $jit]
        ) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($element, 'DOMElement', $prop),
                $jit,
                JITVariable::TYPE_VALUE
            );
        }
    }

    /**
     * ParentNode / NonDocumentTypeChildNode element-nav on loadXML (#19431, #34352).
     *
     * Peer {@see DomNodeLiveMutationRuntime::syncElementNavSlots} (appendChild path).
     */
    private static function storeElementNav(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        Value $firstElement,
        Value $lastElement,
        int $elementCount
    ): void {
        self::ensureElementNavProps($context);
        $objectType = $context->type->object;
        $firstJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $firstElement
        );
        $lastJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $lastElement
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, self::CLASS_ELEMENT, VmDom::PROP_FIRST_ELEMENT_CHILD),
            $firstJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, self::CLASS_ELEMENT, VmDom::PROP_LAST_ELEMENT_CHILD),
            $lastJit,
            JITVariable::TYPE_VALUE
        );
        $countJit = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($elementCount, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, self::CLASS_ELEMENT, VmDom::PROP_CHILD_ELEMENT_COUNT),
            $countJit,
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    private static function linkElementSiblings(
        \PHPCompiler\JIT\Context $context,
        Value $prevElement,
        Value $child
    ): void {
        self::ensureElementNavProps($context);
        $objectType = $context->type->object;
        $childJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $child
        );
        $prevJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $prevElement
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($prevElement, self::CLASS_ELEMENT, VmDom::PROP_NEXT_ELEMENT_SIBLING),
            $childJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($child, self::CLASS_ELEMENT, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING),
            $prevJit,
            JITVariable::TYPE_VALUE
        );
    }

    private static function ensureElementNavProps(\PHPCompiler\JIT\Context $context): void
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        foreach ([
            VmDom::PROP_FIRST_ELEMENT_CHILD => JITVariable::TYPE_VALUE,
            VmDom::PROP_LAST_ELEMENT_CHILD => JITVariable::TYPE_VALUE,
            VmDom::PROP_CHILD_ELEMENT_COUNT => JITVariable::TYPE_NATIVE_LONG,
            VmDom::PROP_NEXT_ELEMENT_SIBLING => JITVariable::TYPE_VALUE,
            VmDom::PROP_PREVIOUS_ELEMENT_SIBLING => JITVariable::TYPE_VALUE,
        ] as $prop => $type) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, $type);
            }
        }
    }

    /** Null element-nav for empty / text-only parents (#34352). */
    private static function clearElementNav(
        \PHPCompiler\JIT\Context $context,
        Value $element
    ): void {
        self::ensureElementNavProps($context);
        $objectType = $context->type->object;
        $nullSlot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $nullPtr = \PHPCompiler\JIT\JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);
        $nullJit = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $nullSlot
        );
        foreach ([VmDom::PROP_FIRST_ELEMENT_CHILD, VmDom::PROP_LAST_ELEMENT_CHILD] as $prop) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($element, self::CLASS_ELEMENT, $prop),
                $nullJit,
                JITVariable::TYPE_NULL
            );
        }
        $zeroJit = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, self::CLASS_ELEMENT, VmDom::PROP_CHILD_ELEMENT_COUNT),
            $zeroJit,
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    /** Null firstChild/lastChild for empty / shallow-clone elements (#32949). */
    private static function clearFirstLast(
        \PHPCompiler\JIT\Context $context,
        Value $element
    ): void {
        self::ensureLinkProps($context);
        $objectType = $context->type->object;
        // Boxed null (not raw object nullptr) so === null / fetch match Zend (#32949).
        $nullSlot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $nullPtr = \PHPCompiler\JIT\JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);
        $nullJit = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $nullSlot
        );
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($element, self::CLASS_ELEMENT, $prop),
                $nullJit,
                JITVariable::TYPE_NULL
            );
        }
    }
}
