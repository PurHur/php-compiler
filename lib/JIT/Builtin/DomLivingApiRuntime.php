<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT link for DOM Living Standard methods (#19507, #21687).
 *
 * Bool ABI is int1 (DomLoadXML pattern). Lower call args before ensureBridge.
 * toggleAttribute uses omit / force-true / force-false ABIs (null force collapses in nested TUs).
 *
 * Thin standalone AOT: contains/getRootNode/isEqualNode/isSameNode via LLVM parentNode/tagName
 * slots (NestedJIT DomRegistry rematerialization loses identity — php-src ext/dom/node.c).
 */
final class DomLivingApiRuntime
{
    public const ABI_CONTAINS = '__phpc_dom_living_contains';

    public const ABI_CONTAINS_NULL = '__phpc_dom_living_contains_null';

    public const ABI_GET_ROOT_NODE = '__phpc_dom_living_get_root_node';

    public const ABI_IS_EQUAL_NODE = '__phpc_dom_living_is_equal_node';

    public const ABI_TOGGLE_ATTRIBUTE_OMIT = '__phpc_dom_living_toggle_omit';

    public const ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE = '__phpc_dom_living_toggle_force_true';

    public const ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE = '__phpc_dom_living_toggle_force_false';

    public static function invokeContains(Context $context, Variable $receiver, Variable $other): Value
    {
        if (Variable::TYPE_NULL === $other->type) {
            $receiverLlvm = self::loadObject($context, $receiver);
            JitDomDocumentMethodKernel::ensureContainsNullBridge($context);

            return $context->builder->call(
                $context->lookupFunction(self::ABI_CONTAINS_NULL),
                $receiverLlvm
            );
        }
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::containsViaParentSlots($context, $receiver, $other);
        }
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);
        JitDomDocumentMethodKernel::ensureContainsBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CONTAINS),
            $receiverLlvm,
            $otherLlvm
        );
    }

    /**
     * DOMNode::contains via parentNode LLVM slots (#21687).
     * Walk other→…→parent looking for receiver (pointer identity).
     */
    private static function containsViaParentSlots(
        Context $context,
        Variable $receiver,
        Variable $other
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_contains_slots');
        $i1 = $context->getTypeFromString('int1');
        $objPtr = $context->getTypeFromString('__object__*');
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);

        $fn = $context->builder->getInsertBlock()->getParent();
        $hit = $fn->appendBasicBlock('dom_contains_hit');
        $miss = $fn->appendBasicBlock('dom_contains_miss');
        $done = $fn->appendBasicBlock('dom_contains_done');

        $same = $context->builder->icmp(Builder::INT_EQ, $receiverLlvm, $otherLlvm);
        $startWalk = $fn->appendBasicBlock('dom_contains_start');
        $context->builder->branchIf($same, $hit, $startWalk);

        $context->builder->positionAtEnd($startWalk);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        $docClassId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, Variable::TYPE_VALUE);
        }
        $objMap = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');

        $current = $otherLlvm;
        for ($hop = 0; $hop < 8; ++$hop) {
            $classIdVal = $context->builder->load(
                $context->builder->structGep($current, $objMap['class_id'])
            );
            $isDoc = $context->builder->icmp(
                Builder::INT_EQ,
                $classIdVal,
                $i64->constInt($docClassId, false)
            );
            $afterDoc = $fn->appendBasicBlock('dom_contains_d'.$hop);
            $context->builder->branchIf($isDoc, $miss, $afterDoc);
            $context->builder->positionAtEnd($afterDoc);

            $parentVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $current,
                'DOMElement',
                VmDom::PROP_PARENT_NODE,
                $elementClassId
            );
            $parentRaw = JitValueBox::valuePtrFromVariable($context, $parentVar);
            $parentIsNull = JitNestedHelperCoerce::isHelperResultNull($context, $parentRaw);
            $afterNull = $fn->appendBasicBlock('dom_contains_n'.$hop);
            $context->builder->branchIf($parentIsNull, $miss, $afterNull);
            $context->builder->positionAtEnd($afterNull);
            $parentObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::normalizeValuePtr($context, $parentRaw)
            );
            $parentObjNull = $context->builder->icmp(
                Builder::INT_EQ,
                $parentObj,
                $objPtr->constNull()
            );
            $afterObj = $fn->appendBasicBlock('dom_contains_o'.$hop);
            $context->builder->branchIf($parentObjNull, $miss, $afterObj);
            $context->builder->positionAtEnd($afterObj);
            $isHit = $context->builder->icmp(Builder::INT_EQ, $parentObj, $receiverLlvm);
            $cont = $fn->appendBasicBlock('dom_contains_c'.$hop);
            $context->builder->branchIf($isHit, $hit, $cont);
            $context->builder->positionAtEnd($cont);
            $current = $parentObj;
        }
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($hit);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $hit);
        $phi->addIncoming($i1->constInt(0, false), $miss);

        return $phi;
    }

    public static function invokeGetRootNode(Context $context, Variable $receiver): Value
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            // Return raw __object__* (same ABI as createElement materialize). Boxing into
            // __value__* makes `$root === $doc` compile as string-vs-value and abort (#21687).
            return self::getRootNodeViaParentSlots($context, $receiver);
        }
        $receiverLlvm = self::loadObject($context, $receiver);
        JitDomDocumentMethodKernel::ensureGetRootNodeBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_GET_ROOT_NODE),
            $receiverLlvm
        );
    }

    /**
     * DOMNode::getRootNode (#21687, #21766).
     * Walk parentNode until null; return topmost node (php-src ext/dom/node.c dom_get_root_node).
     */
    private static function getRootNodeViaParentSlots(Context $context, Variable $receiver): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_get_root_slots');
        $objPtr = $context->getTypeFromString('__object__*');
        $current = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, Variable::TYPE_VALUE);
        }

        $fn = $context->builder->getInsertBlock()->getParent();
        $done = $fn->appendBasicBlock('dom_root_done');
        $objMap = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');
        $docClassId = $objectType->lookup('DOMDocument');
        /** @var list<array{0: \PHPLLVM\BasicBlock, 1: Value}> */
        $stopIncomings = [];

        for ($hop = 0; $hop < 8; ++$hop) {
            $stopHere = $fn->appendBasicBlock('dom_root_stop'.$hop);
            $cont = $fn->appendBasicBlock('dom_root_cont'.$hop);

            $classIdVal = $context->builder->load(
                $context->builder->structGep($current, $objMap['class_id'])
            );
            $isDoc = $context->builder->icmp(
                Builder::INT_EQ,
                $classIdVal,
                $i64->constInt($docClassId, false)
            );
            $afterDoc = $fn->appendBasicBlock('dom_root_d'.$hop);
            $context->builder->branchIf($isDoc, $stopHere, $afterDoc);
            $context->builder->positionAtEnd($afterDoc);

            $parentVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $current,
                'DOMElement',
                VmDom::PROP_PARENT_NODE,
                $elementClassId
            );
            $parentRaw = JitValueBox::valuePtrFromVariable($context, $parentVar);
            $parentIsNull = JitNestedHelperCoerce::isHelperResultNull($context, $parentRaw);
            $afterNull = $fn->appendBasicBlock('dom_root_n'.$hop);
            $context->builder->branchIf($parentIsNull, $stopHere, $afterNull);
            $context->builder->positionAtEnd($afterNull);
            $parentObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::normalizeValuePtr($context, $parentRaw)
            );
            $parentObjNull = $context->builder->icmp(
                Builder::INT_EQ,
                $parentObj,
                $objPtr->constNull()
            );
            $afterObj = $fn->appendBasicBlock('dom_root_o'.$hop);
            $context->builder->branchIf($parentObjNull, $stopHere, $afterObj);
            $context->builder->positionAtEnd($afterObj);
            $context->builder->branch($cont);

            $context->builder->positionAtEnd($stopHere);
            $context->builder->branch($done);
            $stopIncomings[] = [$stopHere, $current];

            $context->builder->positionAtEnd($cont);
            $current = $parentObj;
        }

        $fallthrough = $fn->appendBasicBlock('dom_root_fall');
        $context->builder->branch($fallthrough);
        $context->builder->positionAtEnd($fallthrough);
        $context->builder->branch($done);
        $stopIncomings[] = [$fallthrough, $current];

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($objPtr);
        foreach ($stopIncomings as [$block, $value]) {
            $phi->addIncoming($value, $block);
        }

        return $phi;
    }

    public static function invokeIsSameNode(Context $context, Variable $receiver, Variable $other): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_issame_slots');
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);

        return $context->builder->icmp(Builder::INT_EQ, $receiverLlvm, $otherLlvm);
    }

    public static function invokeIsEqualNode(Context $context, Variable $receiver, Variable $other): Value
    {
        // php-src stub ?DOMNode — compile-time null → false (#24462, ext/dom/node.c).
        if (Variable::TYPE_NULL === $other->type || $other->isNullConstant) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_isequal_null_const');

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::isEqualNodeViaTagName($context, $receiver, $other);
        }
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);
        JitDomDocumentMethodKernel::ensureIsEqualNodeBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_IS_EQUAL_NODE),
            $receiverLlvm,
            $otherLlvm
        );
    }

    /**
     * Thin AOT isEqualNode: pointer identity or equal tagName (#21687).
     * Sufficient for leaf elements created via createElement (no DomRegistry).
     */
    private static function isEqualNodeViaTagName(
        Context $context,
        Variable $receiver,
        Variable $other
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_isequal_slots');
        $i1 = $context->getTypeFromString('int1');
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);

        $fn = $context->builder->getInsertBlock()->getParent();
        $hit = $fn->appendBasicBlock('dom_isequal_hit');
        $miss = $fn->appendBasicBlock('dom_isequal_miss');
        $cmpTags = $fn->appendBasicBlock('dom_isequal_tags');
        $done = $fn->appendBasicBlock('dom_isequal_done');

        $same = $context->builder->icmp(Builder::INT_EQ, $receiverLlvm, $otherLlvm);
        $context->builder->branchIf($same, $hit, $cmpTags);

        $context->builder->positionAtEnd($cmpTags);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, 'tagName')) {
            $objectType->defineProperty($elementClassId, 'tagName', Variable::TYPE_STRING);
        }
        $tagA = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $receiverLlvm,
            'DOMElement',
            'tagName',
            $elementClassId
        );
        $tagB = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $otherLlvm,
            'DOMElement',
            'tagName',
            $elementClassId
        );
        $strA = $context->helper->loadValue($tagA);
        $strB = $context->helper->loadValue($tagB);
        $cmp = JitStringCompare::strcmp($context, $strA, $strB);
        $i64 = $context->getTypeFromString('int64');
        $eq = $context->builder->icmp(Builder::INT_EQ, $cmp, $i64->constInt(0, false));
        $context->builder->branchIf($eq, $hit, $miss);

        $context->builder->positionAtEnd($hit);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $hit);
        $phi->addIncoming($i1->constInt(0, false), $miss);

        return $phi;
    }

    public static function invokeToggleAttribute(
        Context $context,
        Variable $receiver,
        Variable $name,
        ?Variable $force
    ): Value {
        $nameLlvm = JitStringArg::lower($context, $name, 'DOMElement::toggleAttribute() name');
        $receiverLlvm = self::loadObject($context, $receiver);
        $abi = self::ABI_TOGGLE_ATTRIBUTE_OMIT;
        if (null !== $force && Variable::TYPE_NULL !== $force->type) {
            if (Variable::TYPE_NATIVE_BOOL === $force->type) {
                $raw = $context->helper->loadValue($force);
                if (method_exists($raw, 'isConstant') && $raw->isConstant() && method_exists($raw, 'getConstantValue')) {
                    $abi = ((int) $raw->getConstantValue() !== 0)
                        ? self::ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE
                        : self::ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE;
                }
            } elseif (Variable::TYPE_NATIVE_LONG === $force->type && null !== $force->compileTimeLong) {
                $abi = (0 !== $force->compileTimeLong)
                    ? self::ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE
                    : self::ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE;
            }
        }
        if (self::ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE === $abi) {
            JitDomDocumentMethodKernel::ensureToggleAttributeForceTrueBridge($context);
        } elseif (self::ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE === $abi) {
            JitDomDocumentMethodKernel::ensureToggleAttributeForceFalseBridge($context);
        } else {
            JitDomDocumentMethodKernel::ensureToggleAttributeOmitBridge($context);
        }

        return $context->builder->call(
            $context->lookupFunction($abi),
            $receiverLlvm,
            $nameLlvm
        );
    }

    private static function loadObject(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOM living API arg must be object or value box');
    }
}
