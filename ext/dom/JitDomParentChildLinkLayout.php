<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Parent firstChild/lastChild slot layout for thin-AOT DOM trees (#32611).
 *
 * DOMDocument stores child edges on {@see DOMDocument} (peer {@see JitDomAppendChildUserScript}).
 * DOMElement createElement nodes use {@see DOMElement} — DOMNode firstChild clobbers tagName (#32361).
 *
 * php-src: ext/dom/node.c dom_node_children / xmlAddChild
 */
final class JitDomParentChildLinkLayout
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_NODE = 'DOMNode';

    private const CLASS_ELEMENT = 'DOMElement';

    public static function ensureChildEdgeProperties(Context $context): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup(self::CLASS_NODE);
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
            if (!$objectType->hasProperty($docClassId, $prop)) {
                $objectType->defineProperty($docClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_FIRST_ELEMENT_CHILD)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_FIRST_ELEMENT_CHILD, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_FIRST_ELEMENT_CHILD)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_FIRST_ELEMENT_CHILD, JITVariable::TYPE_VALUE);
        }
    }

    public static function loadFirstChild(Context $context, Value $parent, string $labelPrefix): Value
    {
        return self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, $labelPrefix.'_first');
    }

    public static function loadLastChild(Context $context, Value $parent, string $labelPrefix): Value
    {
        return self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $labelPrefix.'_last');
    }

    public static function storeFirstChild(Context $context, Value $parent, JITVariable $value): void
    {
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, $value);
    }

    public static function storeLastChild(Context $context, Value $parent, JITVariable $value): void
    {
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $value);
    }

    public static function firstChildSlot(Context $context, Value $parent): Value
    {
        return self::childEdgeSlotPhi($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_pcl_fc_slot');
    }

    public static function storeFirstChildSlot(Context $context, Value $slot, JITVariable $value): void
    {
        $context->type->object->propertyStore($slot, $value, JITVariable::TYPE_VALUE);
    }

    public static function loadFirstElementChild(Context $context, Value $parent, string $labelPrefix): Value
    {
        return self::loadParentElementChildEdge(
            $context,
            $parent,
            VmDom::PROP_FIRST_ELEMENT_CHILD,
            $labelPrefix.'_fec'
        );
    }

    public static function firstElementChildSlot(Context $context, Value $parent): Value
    {
        self::ensureChildEdgeProperties($context);

        return $context->type->object->propertySlotFor(
            $parent,
            self::CLASS_ELEMENT,
            VmDom::PROP_FIRST_ELEMENT_CHILD
        );
    }

    public static function storeFirstElementChild(Context $context, Value $parent, JITVariable $value): void
    {
        self::ensureChildEdgeProperties($context);
        $isDoc = self::isDocument($context, $parent, 'dom_pcl_store_fec');
        $bbDoc = BasicBlockHelper::append($context, 'dom_pcl_store_fec_doc');
        $bbEl = BasicBlockHelper::append($context, 'dom_pcl_store_fec_el');
        $merge = BasicBlockHelper::append($context, 'dom_pcl_store_fec_done');
        $context->builder->branchIf($isDoc, $bbDoc, $bbEl);

        $objectType = $context->type->object;

        $context->builder->positionAtEnd($bbDoc);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, self::CLASS_DOCUMENT, VmDom::PROP_FIRST_ELEMENT_CHILD),
            $value,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($bbEl);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, self::CLASS_ELEMENT, VmDom::PROP_FIRST_ELEMENT_CHILD),
            $value,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
    }

    private static function loadChildEdge(Context $context, Value $parent, string $prop, string $labelPrefix): Value
    {
        self::ensureChildEdgeProperties($context);
        $isDoc = self::isDocument($context, $parent, $labelPrefix);
        $bbDoc = BasicBlockHelper::append($context, $labelPrefix.'_doc');
        $bbEl = BasicBlockHelper::append($context, $labelPrefix.'_el');
        $bbDocDone = BasicBlockHelper::append($context, $labelPrefix.'_doc_done');
        $bbElDone = BasicBlockHelper::append($context, $labelPrefix.'_el_done');
        $merge = BasicBlockHelper::append($context, $labelPrefix.'_merge');
        $context->builder->branchIf($isDoc, $bbDoc, $bbEl);

        $objPtrTy = $context->getTypeFromString('__object__*');

        $context->builder->positionAtEnd($bbDoc);
        $docVal = self::loadLinkFlat($context, $parent, self::CLASS_DOCUMENT, $prop, $labelPrefix.'_read_doc');
        $context->builder->branch($bbDocDone);

        $context->builder->positionAtEnd($bbEl);
        $elVal = self::loadLinkFlat($context, $parent, self::CLASS_ELEMENT, $prop, $labelPrefix.'_read_el');
        $context->builder->branch($bbElDone);

        $context->builder->positionAtEnd($bbDocDone);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($bbElDone);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($docVal, $bbDocDone);
        $phi->addIncoming($elVal, $bbElDone);

        return $phi;
    }

    private static function storeChildEdge(
        Context $context,
        Value $parent,
        string $prop,
        JITVariable $value
    ): void {
        self::ensureChildEdgeProperties($context);
        $isDoc = self::isDocument($context, $parent, 'dom_pcl_store_'.$prop);
        $bbDoc = BasicBlockHelper::append($context, 'dom_pcl_store_'.$prop.'_doc');
        $bbEl = BasicBlockHelper::append($context, 'dom_pcl_store_'.$prop.'_el');
        $merge = BasicBlockHelper::append($context, 'dom_pcl_store_'.$prop.'_done');
        $context->builder->branchIf($isDoc, $bbDoc, $bbEl);

        $objectType = $context->type->object;

        $context->builder->positionAtEnd($bbDoc);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, self::CLASS_DOCUMENT, $prop),
            $value,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($bbEl);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, self::CLASS_ELEMENT, $prop),
            $value,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
    }

    private static function loadParentElementChildEdge(
        Context $context,
        Value $parent,
        string $prop,
        string $labelPrefix
    ): Value {
        self::ensureChildEdgeProperties($context);
        $isDoc = self::isDocument($context, $parent, $labelPrefix);
        $bbDoc = BasicBlockHelper::append($context, $labelPrefix.'_doc');
        $bbEl = BasicBlockHelper::append($context, $labelPrefix.'_el');
        $bbDocDone = BasicBlockHelper::append($context, $labelPrefix.'_doc_done');
        $bbElDone = BasicBlockHelper::append($context, $labelPrefix.'_el_done');
        $merge = BasicBlockHelper::append($context, $labelPrefix.'_merge');
        $context->builder->branchIf($isDoc, $bbDoc, $bbEl);

        $objPtrTy = $context->getTypeFromString('__object__*');

        $context->builder->positionAtEnd($bbDoc);
        $docVal = self::loadLinkFlat($context, $parent, self::CLASS_DOCUMENT, $prop, $labelPrefix.'_read_doc');
        $context->builder->branch($bbDocDone);

        $context->builder->positionAtEnd($bbEl);
        $elVal = self::loadLinkFlat($context, $parent, self::CLASS_ELEMENT, $prop, $labelPrefix.'_read_el');
        $context->builder->branch($bbElDone);

        $context->builder->positionAtEnd($bbDocDone);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($bbElDone);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($docVal, $bbDocDone);
        $phi->addIncoming($elVal, $bbElDone);

        return $phi;
    }

    private static function childEdgeSlotPhi(Context $context, Value $parent, string $prop, string $labelPrefix): Value
    {
        self::ensureChildEdgeProperties($context);
        $isDoc = self::isDocument($context, $parent, $labelPrefix);
        $bbDoc = BasicBlockHelper::append($context, $labelPrefix.'_doc');
        $bbEl = BasicBlockHelper::append($context, $labelPrefix.'_el');
        $merge = BasicBlockHelper::append($context, $labelPrefix.'_merge');
        $context->builder->branchIf($isDoc, $bbDoc, $bbEl);

        $slotTy = $context->getTypeFromString('__object__*')->getPointerTo();

        $objectType = $context->type->object;

        $context->builder->positionAtEnd($bbDoc);
        $docSlot = $objectType->propertySlotFor($parent, self::CLASS_DOCUMENT, $prop);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($bbEl);
        $elSlot = $objectType->propertySlotFor($parent, self::CLASS_ELEMENT, $prop);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($slotTy);
        $phi->addIncoming($docSlot, $bbDoc);
        $phi->addIncoming($elSlot, $bbEl);

        return $phi;
    }

    private static function isDocument(Context $context, Value $obj, string $labelPrefix): Value
    {
        $objectType = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $classId = $context->builder->load($context->builder->structGep($obj, $map['class_id']));
        $isDoc = $i1->constInt(0, false);
        foreach ([self::CLASS_DOCUMENT, 'Dom\\Document', 'Dom\\XMLDocument', 'Dom\\HTMLDocument'] as $className) {
            try {
                $expected = $objectType->lookup($className);
            } catch (\Throwable $e) {
                continue;
            }
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($expected, false)
            );
            $isDoc = $context->builder->or($isDoc, $match);
        }

        return $isDoc;
    }

    /**
     * Load a child-edge object in the current block (no inner phi — caller owns merge).
     */
    private static function loadLinkFlat(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        string $label
    ): Value {
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $voidPtr = $context->getTypeFromString('void*');
        $slot = $objectType->propertySlotFor($obj, $className, $prop);
        $slotPtr = $context->builder->load($slot);
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, $label.'_null');
        $readBlock = BasicBlockHelper::append($context, $label.'_read');
        $merge = BasicBlockHelper::append($context, $label.'_merge');
        $context->builder->branchIf($slotNull, $nullBlock, $readBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($readBlock);
        $edgeObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($slotPtr, $context->getTypeFromString('__value__*'))
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $nullBlock);
        $phi->addIncoming($edgeObj, $readBlock);

        return $phi;
    }

    private static function loadLink(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        string $label
    ): Value {
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $voidPtr = $context->getTypeFromString('void*');
        $slot = $objectType->propertySlotFor($obj, $className, $prop);
        $slotPtr = $context->builder->load($slot);
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, $label.'_null');
        $readBlock = BasicBlockHelper::append($context, $label.'_read');
        $merge = BasicBlockHelper::append($context, $label.'_merge');
        $context->builder->branchIf($slotNull, $nullBlock, $readBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($readBlock);
        $edgeObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($slotPtr, $context->getTypeFromString('__value__*'))
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $nullBlock);
        $phi->addIncoming($edgeObj, $readBlock);

        return $phi;
    }

    public static function loadSibling(
        Context $context,
        Value $obj,
        string $prop,
        string $label
    ): Value {
        return self::loadLinkFlat($context, $obj, self::CLASS_ELEMENT, $prop, $label);
    }

    public static function storeSibling(
        Context $context,
        Value $obj,
        string $prop,
        JITVariable $value
    ): void {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_ELEMENT, $prop),
            $value,
            JITVariable::TYPE_VALUE
        );
    }

    public static function storeParentNode(Context $context, Value $child, JITVariable $parent): void
    {
        $elementClassId = $context->type->object->lookup(self::CLASS_ELEMENT);
        if (!$context->type->object->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $context->type->object->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($child, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $parent,
            JITVariable::TYPE_VALUE
        );
    }

    public static function loadObjectArg(Context $context, JITVariable $arg): Value
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

        throw new \LogicException('DOM node arg must be object or value box');
    }
}
