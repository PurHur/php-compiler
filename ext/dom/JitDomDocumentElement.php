<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMDocument / Dom\XMLDocument::$documentElement (#18478, #19455, #23251, #27108). */
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

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        if (!JitDomDocumentMethodKernel::shouldUse($context)) {
            return $objectType->propertyFetchOrdinary(
                $obj,
                self::CLASS_DOCUMENT,
                self::PROP_DOCUMENT_ELEMENT
            );
        }

        // Prefer the documentElement pinned at loadXML / loadHTML / createFromString
        // (#26757, #27108, #29487). Pure user-script loadHTML also pins html root.
        if (null !== JitDomLoadXMLUserScript::lastCompileTimeXml()
            || null !== JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml()
            || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
        ) {
            $docClass = JitDomLoadXMLUserScript::lastDocumentClass() ?? self::CLASS_DOCUMENT;
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
            JitDomGetNodePath::annotateDocumentElement($result);

            return $result;
        }

        return self::boxNull($context);
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
        string $parentPath = ''
    ): void {
        if ('' === $parentPath) {
            $parentPath = '/'.DomParseSimpleXmlJitHelper::rootTagArgv($xml);
        }
        self::syncChildrenFromXml($context, $element, $xml, $parentPath);
    }

    private static function syncChildrenFromXml(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        string $xml,
        string $parentPath
    ): void {
        // Include blank text / comments so childNodes->length matches Zend (#27260).
        $children = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        if ([] === $children) {
            return;
        }

        $objectType = $context->type->object;
        $prev = null;
        $first = null;
        $last = null;
        foreach ($children as $idx => $node) {
            $child = match ($node['kind']) {
                'comment' => JitDomCreateComment::materialize($context, $node['data']),
                'text' => JitDomCreateTextNode::materialize($context, $node['data']),
                default => JitDomCreateElement::materializeElementFromLiteral($context, $node['data']),
            };
            $segment = DomParseSimpleXmlJitHelper::nodePathSegmentArgv($children, $idx);
            if ('element' === $node['kind'] && null !== $segment && '' !== $segment) {
                $childPath = rtrim($parentPath, '/').'/'.$segment;
                JitDomGetNodePath::storeOn($context, $child, self::CLASS_ELEMENT, $childPath);
                $inner = $node['inner'] ?? '';
                if ('' !== $inner) {
                    $outer = '<'.$node['data'].'>'.$inner.'</'.$node['data'].'>';
                    self::syncChildrenFromXml($context, $child, $outer, $childPath);
                }
            }
            if ('element' === $node['kind'] && isset($node['open']) && \is_string($node['open'])) {
                JitDomCreateElement::storeAttributesPresence(
                    $context,
                    $child,
                    [] !== DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv($node['open'])
                );
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
            if (null === $first) {
                $first = $child;
            }
            $last = $child;
            $prev = $child;
        }
        if (null !== $first && null !== $last) {
            self::storeFirstLast($context, $element, $first, $last);
        }
        self::storeChildNodesLength($context, $element, \count($children), $first, $last);
    }

    /**
     * Attach a DOMNodeList with the given length for user-script childNodes (#23251).
     *
     * Optional first/last pin {@see VmDom::PROP_CHILD_NODES_OWNER} + __phpcItemN so
     * item(0)/item(1) work before any LiveSlots rewrite (#28672 / #27410).
     */
    public static function storeChildNodesLength(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        int $length,
        ?Value $item0 = null,
        ?Value $item1 = null
    ): void {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $listClassId = $objectType->lookup('DOMNodeList');
        // VALUE — peer LiveSlots / #27216. TYPE_OBJECT leaves NULL-tagged slots as
        // garbage pointers; after appendChild rewrite, length fetch segfaults (#28672).
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($nodeClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
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
            $objectType->propertySlotFor($element, 'DOMNode', VmDom::PROP_CHILD_NODES),
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
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
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

    private static function boxNull(\PHPCompiler\JIT\Context $context): JITVariable
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
}
