<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode child/sibling edge properties after live mutation
 * (#18951, #28671, #33273).
 *
 * firstChild/lastChild stamps are absolute; nextSibling/previousSibling advance
 * the receiver's compile-time child index so replaceChild InnerXml splices the
 * correct sibling (middle via firstChild->nextSibling was replacing index 0).
 */
final class JitDomNodeChildProperty
{
    private const CLASS_NODE = 'DOMNode';

    /** Last firstChild/lastChild/nextSibling/previousSibling compile-time tag. */
    public static ?string $lastFetchedTagName = null;

    public static ?int $lastFetchedChildIndex = null;

    /** @var array<string, string>|null Open-tag attrs for {@see $lastFetchedChildIndex} (#34050). */
    public static ?array $lastFetchedAttributes = null;

    /** PI target when {@see $lastFetchedChildIndex} is a processing-instruction (#35098). */
    public static ?string $lastFetchedPiTarget = null;

    public static function isDomNodeChildProperty(string $classLc, string $propLc): bool
    {
        if (!\in_array(
            strtolower($propLc),
            ['firstchild', 'lastchild', 'nextsibling', 'previoussibling'],
            true
        )) {
            return false;
        }
        $classLc = strtolower($classLc);
        if (str_starts_with($classLc, 'dom')) {
            return true;
        }

        // documentElement temps often lose DOMElement userType (#23251 / #28671).
        return null !== JitDomLoadXMLUserScript::lastCompileTimeXml()
            && \in_array($classLc, ['object', 'stdclass', ''], true);
    }

    public static function fetch(
        Object_ $objectType,
        Value $obj,
        string $propName,
        string $classLc = 'domnode',
        ?JITVariable $receiverVar = null
    ): JITVariable {
        $context = $objectType->jitContext();
        // Attr child/sibling edges must not GEP DOMElement slots (#35227 / #35185).
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_child_edge_fetch');
        $isAttr = JitDomAppendChildLiveSlots::isAttrNode($context, $obj);
        $bbAttr = BasicBlockHelper::append($context, 'dom_child_edge_attr');
        $bbElem = BasicBlockHelper::append($context, 'dom_child_edge_elem');
        $bbOut = BasicBlockHelper::append($context, 'dom_child_edge_out');
        $context->builder->branchIf($isAttr, $bbAttr, $bbElem);

        $resultTy = $context->getTypeFromString('__value__*');

        $context->builder->positionAtEnd($bbAttr);
        // Safe null until Attr tree-link slots are seeded like VmDom::syncAttributeTreeLinks.
        // Returning null beats SIGSEGV on Element-layout GEP (#35227).
        $attrNull = self::boxNullChildEdge($context);
        $attrPtr = JitValueBox::valuePtrFromVariable($context, $attrNull);
        $attrPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbOut);

        $context->builder->positionAtEnd($bbElem);
        $slotClass = self::childEdgeClass($classLc);
        $elemResult = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            $slotClass,
            $propName,
            $objectType->lookup($slotClass)
        );
        // Result object is the child (AOT element/text stand-in), not the parent
        // whose firstChild pointer lives on $slotClass. CFG types the temp unknown;
        // chained `$el->firstChild->nodeName` would then defineProperty nodeName on
        // stdClass and GEP past the allocation (SIGSEGV after setAttribute adds
        // DOMAttr::$nodeName as a second same-name slot).
        $elemResult->classUserType = 'DOMElement';
        self::annotateCompileTimeChild($elemResult, $propName, $receiverVar);
        $propLc = strtolower($propName);
        // GetNodePath's child-fetch annotator only knows first/last (defaults other
        // props to index 0) — do not let it wipe nextSibling/previousSibling stamps (#33273).
        if (!\in_array($propLc, ['nextsibling', 'previoussibling'], true)) {
            JitDomGetNodePath::annotateChildFetch($elemResult, $propName);
        }
        $elemPtr = JitValueBox::valuePtrFromVariable($context, $elemResult);
        $elemPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbOut);

        $context->builder->positionAtEnd($bbOut);
        $phi = $context->builder->phi($resultTy);
        $phi->addIncoming($attrPtr, $attrPred);
        $phi->addIncoming($elemPtr, $elemPred);

        $out = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $phi)
        );
        $out->classUserType = 'DOMElement';

        return $out;
    }

    private static function boxNullChildEdge(\PHPCompiler\JIT\Context $context): JITVariable
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $ptr)
        );
    }

    /**
     * Element allocations store first/last on DOMElement. Using DOMNode indices
     * on those objects aliases tagName/nodeName (#32361). Documents keep DOMNode.
     * appendChild() return temps are typed DOMNode but allocate as DOMElement.
     */
    private static function childEdgeClass(string $classLc): string
    {
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        if (str_contains($classLc, 'document') && !str_contains($classLc, 'element')) {
            return self::CLASS_NODE;
        }

        return 'DOMElement';
    }

    /**
     * Seed firstElementChild / lastElementChild compile-time tag/index (#35017).
     *
     * Peer of {@see annotateCompileTimeChild} for firstChild. Without this,
     * importNode(documentElement->firstElementChild) falls back to the
     * documentElement GetNodePath stamp and copies the root (re-#33918).
     */
    public static function annotateCompileTimeElementChild(
        JITVariable $result,
        string $propName,
        ?JITVariable $receiverVar = null
    ): void {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $propLc = strtolower($propName);
        if ('firstelementchild' !== $propLc && 'lastelementchild' !== $propLc) {
            return;
        }
        $nodes = self::compileTimeChildNodesForReceiver($receiverVar);
        if ([] === $nodes) {
            return;
        }
        $index = null;
        if ('firstelementchild' === $propLc) {
            foreach ($nodes as $i => $node) {
                if ('element' === ($node['kind'] ?? '')) {
                    $index = $i;
                    break;
                }
            }
        } else {
            for ($i = \count($nodes) - 1; $i >= 0; --$i) {
                if ('element' === ($nodes[$i]['kind'] ?? '')) {
                    $index = $i;
                    break;
                }
            }
        }
        if (null === $index) {
            return;
        }
        self::stampChildIndex($result, $nodes, $index);
        // Multi-segment path so importNode recovery does not treat this as documentElement.
        JitDomGetNodePath::annotateElementChildPath($result, $propLc, $receiverVar, $index);
    }

    /**
     * Seed nextElementSibling / previousElementSibling compile-time tag/index (#35021).
     *
     * Sibling edges walk the *parent* child list (GetNodePath lastParentInner), not
     * the receiver element's empty inner — otherwise importNode falls back to the
     * prior FEC/LEC stamp and copies the wrong element.
     */
    public static function annotateCompileTimeElementSibling(
        JITVariable $result,
        string $propName,
        ?JITVariable $receiverVar = null
    ): void {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $propLc = strtolower($propName);
        if ('nextelementsibling' !== $propLc && 'previouselementsibling' !== $propLc) {
            return;
        }
        $nodes = self::compileTimeParentSiblingNodes($receiverVar);
        if ([] === $nodes) {
            return;
        }
        $base = $receiverVar?->compileTimeDomChildIndex
            ?? self::$lastFetchedChildIndex
            ?? null;
        if (null === $base) {
            return;
        }
        $index = null;
        if ('nextelementsibling' === $propLc) {
            for ($i = $base + 1, $n = \count($nodes); $i < $n; ++$i) {
                if ('element' === ($nodes[$i]['kind'] ?? '')) {
                    $index = $i;
                    break;
                }
            }
        } else {
            for ($i = $base - 1; $i >= 0; --$i) {
                if ('element' === ($nodes[$i]['kind'] ?? '')) {
                    $index = $i;
                    break;
                }
            }
        }
        if (null === $index) {
            return;
        }
        self::stampChildIndex($result, $nodes, $index);
        // null receiver: do not treat the sibling child as the parent for path recovery.
        JitDomGetNodePath::annotateElementChildPath($result, $propLc, null, $index);
    }

    /**
     * Seed child index/tag from loadXML literal so replaceChild can rebuild
     * PROP_USER_SCRIPT_INNER_XML without collapsing siblings (#28671 / #33273).
     *
     * Prefer the *receiver* tree — lastCompileTimeXml is the globally last load and
     * is the destination document during cross-document importNode (#32978).
     */
    private static function annotateCompileTimeChild(
        JITVariable $result,
        string $propName,
        ?JITVariable $receiverVar = null
    ): void {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $propLc = strtolower($propName);
        if ('nextsibling' === $propLc || 'previoussibling' === $propLc) {
            // Parent sibling list — receiver is the child edge, not the parent (#35021).
            $nodes = self::compileTimeParentSiblingNodes($receiverVar);
            if ([] === $nodes) {
                return;
            }
            $base = $receiverVar?->compileTimeDomChildIndex
                ?? self::$lastFetchedChildIndex
                ?? null;
            if (null === $base) {
                return;
            }
            $index = 'nextsibling' === $propLc ? $base + 1 : $base - 1;
            if ($index < 0 || $index >= \count($nodes)) {
                return;
            }
            self::stampChildIndex($result, $nodes, $index);
            JitDomGetNodePath::annotateElementChildPath($result, $propLc, null, $index);

            return;
        }
        $nodes = self::compileTimeChildNodesForReceiver($receiverVar);
        if ([] === $nodes) {
            return;
        }
        if ('firstchild' === $propLc) {
            self::stampChildIndex($result, $nodes, 0);

            return;
        }
        if ('lastchild' === $propLc) {
            self::stampChildIndex($result, $nodes, \count($nodes) - 1);

            return;
        }
    }

    /**
     * Siblings under the parent of a child-edge receiver (next/previousSibling).
     *
     * Must not use the child's empty InnerXml or the process-global last loadXML
     * (destination wins during importNode) — prefer GetNodePath's lastParentInner
     * stamped by documentElement / parent walks (#35021).
     *
     * @return list<array{kind: string, data: string, inner?: string, open?: string}>
     */
    private static function compileTimeParentSiblingNodes(?JITVariable $childVar): array
    {
        $parentInner = JitDomGetNodePath::$lastParentInner;
        if (null !== $parentInner && '' !== $parentInner) {
            return DomParseSimpleXmlJitHelper::parseSiblingNodesArgv($parentInner);
        }
        if (null !== $childVar) {
            $bound = $childVar->compileTimeDomLoadXml
                ?? JitDomLoadXMLUserScript::compileTimeXmlFor($childVar);
            if (null !== $bound) {
                return DomParseSimpleXmlJitHelper::directChildNodesArgv($bound);
            }
        }

        return [];
    }

    /**
     * Direct children of the property receiver (documentElement / element), not the
     * process-global last loadXML (destination wins after a second load).
     *
     * @return list<array{kind: string, data: string, inner?: string, open?: string}>
     */
    private static function compileTimeChildNodesForReceiver(?JITVariable $receiverVar): array
    {
        if (null !== $receiverVar) {
            $inner = $receiverVar->compileTimeDomInnerXml;
            if (null !== $inner && '' !== $inner) {
                return DomParseSimpleXmlJitHelper::parseSiblingNodesArgv($inner);
            }
            $bound = $receiverVar->compileTimeDomLoadXml
                ?? JitDomLoadXMLUserScript::compileTimeXmlFor($receiverVar);
            if (null !== $bound) {
                return DomParseSimpleXmlJitHelper::directChildNodesArgv($bound);
            }
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return [];
        }

        return DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
    }

    /**
     * @param list<array{kind: string, data: string, inner?: string, open?: string}> $nodes
     */
    private static function stampChildIndex(JITVariable $result, array $nodes, int $index): void
    {
        $result->compileTimeDomChildIndex = $index;
        self::$lastFetchedChildIndex = $index;
        $kind = $nodes[$index]['kind'] ?? '';
        // Text/comment/PI/CDATA payloads for CharacterData methods on firstChild temps
        // (#34314 / #34475 / #34952 / #34949).
        if ('text' === $kind || 'comment' === $kind || 'pi' === $kind || 'cdata' === $kind) {
            $data = 'pi' === $kind
                ? ($nodes[$index]['content'] ?? '')
                : $nodes[$index]['data'];
            $result->compileTimeDomTextData = $data;
            JitDomSubstringData::remember($data);
            // Stamp leaf nodeName discriminators so importNode / cloneNode do not
            // treat comment/CDATA/PI TextData as `#text` (#35098 / peer #35043).
            if ('text' === $kind) {
                $result->compileTimeDomTagName = '#text';
                JitDomCreateTextNode::$lastMaterializedData = $data;
                self::$lastFetchedPiTarget = null;
            } elseif ('comment' === $kind) {
                $result->compileTimeDomTagName = '#comment';
                self::$lastFetchedPiTarget = null;
            } elseif ('cdata' === $kind) {
                $result->compileTimeDomTagName = '#cdata-section';
                self::$lastFetchedPiTarget = null;
            } else {
                $result->compileTimeDomTagName = JitDomCreateProcessingInstruction::TAG_KIND;
                $piTarget = $nodes[$index]['data'];
                self::$lastFetchedPiTarget = $piTarget;
                // PI target on the Variable (global lastFetched* is overwritten by later walks).
                $result->compileTimeDomAttributes = ['target' => $piTarget];
            }
            self::$lastFetchedTagName = null;
            self::$lastFetchedAttributes = null;

            return;
        }
        if ('element' === $kind) {
            $result->compileTimeDomTagName = $nodes[$index]['data'];
            self::$lastFetchedTagName = $nodes[$index]['data'];
            $inner = $nodes[$index]['inner'] ?? null;
            if (null !== $inner) {
                $result->compileTimeDomInnerXml = $inner;
            }
            // Per-element attrs from the open tag — global DomUserScriptAttributeCacheLlvm
            // is keyed only by name and returns the last id= in the document (#34050).
            $open = $nodes[$index]['open'] ?? null;
            if (null !== $open && '' !== $open) {
                $attrs = [];
                foreach (DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv($open) as $pair) {
                    $attrs[$pair['qname']] = $pair['value'];
                    $pos = strpos($pair['qname'], ':');
                    if (false !== $pos) {
                        $attrs[substr($pair['qname'], $pos + 1)] = $pair['value'];
                    }
                }
                if ([] !== $attrs) {
                    $result->compileTimeDomAttributes = $attrs;
                    self::$lastFetchedAttributes = $attrs;
                } else {
                    self::$lastFetchedAttributes = null;
                }
            } else {
                self::$lastFetchedAttributes = null;
            }
        }
    }
}
