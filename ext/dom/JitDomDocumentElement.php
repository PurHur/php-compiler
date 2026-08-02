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

/** LLVM lowering for DOMDocument::$documentElement in user-script AOT (#18478, #19455, #23251). */
final class JitDomDocumentElement
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_DOCUMENT_ELEMENT = 'documentElement';

    public static function isDomDocumentElement(string $classLc, string $propLc): bool
    {
        return 'domdocument' === strtolower($classLc)
            && 'documentelement' === strtolower($propLc);
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

        // Prefer the documentElement pinned at loadXML (pure path) or DomRegistry (#26757).
        // Rematerializing a fresh shallow element each fetch dropped saveXML children and
        // made appendChild mutate a throwaway object.
        if (null !== JitDomLoadXMLUserScript::lastCompileTimeXml()
            || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
        ) {
            $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
            if (!$objectType->hasProperty($docClassId, self::PROP_DOCUMENT_ELEMENT)) {
                $objectType->defineProperty($docClassId, self::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
            }

            return ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $obj,
                self::CLASS_DOCUMENT,
                self::PROP_DOCUMENT_ELEMENT,
                $docClassId
            );
        }

        return self::boxNull($context);
    }

    /**
     * Seed firstChild/lastChild/parentNode/sibling slots from compile-time XML (#19455, #23251).
     *
     * Element children are required so held references survive textContent writes (detach).
     */
    public static function syncChildrenFromXmlPublic(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        string $xml
    ): void {
        self::syncChildrenFromXml($context, $element, $xml);
    }

    private static function syncChildrenFromXml(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        string $xml
    ): void {
        $children = DomParseSimpleXmlJitHelper::directElementChildTags($xml);
        if ([] === $children) {
            $node = DomParseSimpleXmlJitHelper::firstChildNodeArgv($xml);
            if (null === $node || 'comment' !== $node['kind']) {
                return;
            }
            $child = JitDomCreateComment::materialize($context, $node['data']);
            self::linkSingleChild($context, $element, $child);
            self::storeChildNodesLength($context, $element, 1);

            return;
        }

        $objectType = $context->type->object;
        $prev = null;
        $first = null;
        $last = null;
        foreach ($children as $childTag) {
            $child = JitDomCreateElement::materializeElementFromLiteral($context, $childTag);
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
                $objectType->propertyStore(
                    $objectType->propertySlotFor($prev, 'DOMNode', VmDom::PROP_NEXT_SIBLING),
                    $childJit,
                    JITVariable::TYPE_VALUE
                );
                $objectType->propertyStore(
                    $objectType->propertySlotFor($child, 'DOMNode', VmDom::PROP_PREVIOUS_SIBLING),
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
        self::storeChildNodesLength($context, $element, \count($children));
    }

    /** Attach a DOMNodeList with the given length for user-script childNodes (#23251). */
    public static function storeChildNodesLength(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        int $length
    ): void {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $listClassId = $objectType->lookup('DOMNodeList');
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($nodeClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_OBJECT);
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', JITVariable::TYPE_NATIVE_LONG);
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
        $listJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $list
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, 'DOMNode', VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_OBJECT
        );
    }

    private static function ensureLinkProps(\PHPCompiler\JIT\Context $context): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD, VmDom::PROP_NEXT_SIBLING, VmDom::PROP_PREVIOUS_SIBLING] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
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
                $objectType->propertySlotFor($element, 'DOMNode', $prop),
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
