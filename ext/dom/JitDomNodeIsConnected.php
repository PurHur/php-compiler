<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeIsConnectedRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::$isConnected (#19653, #29375).
 *
 * Thin standalone AOT walks parentNode slots (same #21687 pattern as getRootNode /
 * contains) — DomRegistry parentId is not mirrored by JitDomAppendChild.
 */
final class JitDomNodeIsConnected
{
    public static function isDomNodeIsConnected(string $classLc, string $propLc): bool
    {
        if (!\PHPCompiler\CompilerVersion::supportsDomNodeIsConnected()) {
            return false;
        }
        if (!str_starts_with(strtolower($classLc), 'dom')) {
            return false;
        }

        return 'isconnected' === strtolower($propLc);
    }

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $flag = self::isConnectedViaParentSlots($objectType, $obj);
            $slot = JitValueBox::alloc($context);
            $destPtr = JitValueBox::pointer($context, $slot);
            JitValueBox::writeBool($context, $destPtr, $flag);

            return new JITVariable(
                $context,
                JITVariable::TYPE_VALUE,
                JITVariable::KIND_VALUE,
                JitValueBox::normalizeValuePtr($context, $destPtr)
            );
        }

        DomNodeIsConnectedRuntime::ensureLinked($context);
        $flag = $context->builder->call(
            $context->lookupFunction(DomNodeIsConnectedRuntime::ABI_NAME),
            $obj
        );
        $i64 = $context->getTypeFromString('int64');
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $destPtr,
            $context->builder->icmp(Builder::INT_NE, $flag, $i64->constInt(0, false))
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $destPtr)
        );
    }

    /**
     * php-src dom_node_is_connected_read: true iff the document is an ancestor
     * (documents themselves are always connected).
     *
     * @return Value int1
     */
    private static function isConnectedViaParentSlots(Object_ $objectType, Value $obj): Value
    {
        $context = $objectType->jitContext();
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_is_connected_slots');
        $i1 = $context->getTypeFromString('int1');
        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $objMap = $context->structFieldMap['__object__'];
        $docClassId = $objectType->lookup('DOMDocument');
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }

        $fn = $context->builder->getInsertBlock()->getParent();
        $yes = $fn->appendBasicBlock('dom_ic_yes');
        $no = $fn->appendBasicBlock('dom_ic_no');
        $done = $fn->appendBasicBlock('dom_ic_done');

        $classIdVal = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $isDoc = $context->builder->icmp(
            Builder::INT_EQ,
            $classIdVal,
            $i64->constInt($docClassId, false)
        );
        $startWalk = $fn->appendBasicBlock('dom_ic_walk');
        $context->builder->branchIf($isDoc, $yes, $startWalk);

        $context->builder->positionAtEnd($startWalk);
        $current = $obj;
        for ($hop = 0; $hop < 8; ++$hop) {
            $parentVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $current,
                'DOMElement',
                VmDom::PROP_PARENT_NODE,
                $elementClassId
            );
            $parentRaw = JitValueBox::valuePtrFromVariable($context, $parentVar);
            $parentIsNull = JitNestedHelperCoerce::isHelperResultNull($context, $parentRaw);
            $afterNull = $fn->appendBasicBlock('dom_ic_n'.$hop);
            $context->builder->branchIf($parentIsNull, $no, $afterNull);
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
            $afterObj = $fn->appendBasicBlock('dom_ic_o'.$hop);
            $context->builder->branchIf($parentObjNull, $no, $afterObj);
            $context->builder->positionAtEnd($afterObj);

            $parentClass = $context->builder->load(
                $context->builder->structGep($parentObj, $objMap['class_id'])
            );
            $parentIsDoc = $context->builder->icmp(
                Builder::INT_EQ,
                $parentClass,
                $i64->constInt($docClassId, false)
            );
            $cont = $fn->appendBasicBlock('dom_ic_c'.$hop);
            $context->builder->branchIf($parentIsDoc, $yes, $cont);
            $context->builder->positionAtEnd($cont);
            $current = $parentObj;
        }
        $context->builder->branch($no);

        $context->builder->positionAtEnd($yes);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($no);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $yes);
        $phi->addIncoming($i1->constInt(0, false), $no);

        return $phi;
    }
}
