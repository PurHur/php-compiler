<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * DOMNode::$parentNode for user-script AOT — honor textContent free-list stale markers (#23892)
 * and only-child replaceChild detach (#27411).
 *
 * Freed wrappers point parentNode at a module-level sentinel object; reading that
 * raises php-src's dom_objects_not_found() message. Kept-but-detached wrappers
 * keep a null parentNode (Zend: first held child after php_libxml_node_free_list).
 *
 * Thin AOT replaceChild updates the parent's firstChild/lastChild but cannot safely
 * null the replaced node's parentNode slot when that node came from appendChild()'s
 * boxed return (heap corruption / #27216). Instead, parentNode reads treat a node as
 * orphaned when its claimed parent has synced first/last children that do not include it.
 *
 * Reference: php-src ext/dom/php_dom.c / ext/dom/node.c dom_node_replace_child.
 */
final class JitDomParentNodeProperty
{
    private const CLASS_ELEMENT = 'DOMElement';

    private const CLASS_NODE = 'DOMNode';

    private const SENTINEL_GLOBAL = '__phpc_dom_freed_sentinel';

    private const FREED_MESSAGE = 'Couldn\'t fetch DOMElement. Node no longer exists';

    public static function isDomParentNodeProperty(string $classLc, string $propLc): bool
    {
        if ('parentnode' !== strtolower($propLc)) {
            return false;
        }
        $classLc = strtolower($classLc);
        if (str_starts_with($classLc, 'dom')) {
            return true;
        }

        // Temps after documentElement often lose DOMElement userType (#23251 / #23892).
        return null !== JitDomLoadXMLUserScript::lastCompileTimeXml()
            && \in_array($classLc, ['object', 'stdclass', ''], true);
    }

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        if (!JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::fetchDeclaredParent($objectType, $obj);
        }

        self::ensureParentProp($objectType);
        self::ensureChildLinkProps($objectType);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_parent_fetch');

        $resultTy = $context->getTypeFromString('__value__*');
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');

        $nullResult = self::boxNullPtr($context);

        $slot = $objectType->propertySlotFor($obj, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE);
        $slotPtr = $context->builder->load($slot);
        $isNullSlot = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, 'dom_parent_slot_null');
        $readBlock = BasicBlockHelper::append($context, 'dom_parent_slot_read');
        $merge = BasicBlockHelper::append($context, 'dom_parent_slot_merge');
        $context->builder->branchIf($isNullSlot, $nullBlock, $readBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($readBlock);
        $valuePtr = $context->builder->pointerCast(
            $slotPtr,
            $context->getTypeFromString('__value__*')
        );
        $parentObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $parentIsNull = $context->builder->icmp(Builder::INT_EQ, $parentObj, $objPtrTy->constNull());
        $parentNullBlock = BasicBlockHelper::append($context, 'dom_parent_obj_null');
        $checkFreed = BasicBlockHelper::append($context, 'dom_parent_check_freed');
        $context->builder->branchIf($parentIsNull, $parentNullBlock, $checkFreed);

        $context->builder->positionAtEnd($parentNullBlock);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($checkFreed);
        $afterFreed = BasicBlockHelper::append($context, 'dom_parent_after_freed');
        if (JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            $sentinel = self::ensureSentinel($context);
            $isFreed = $context->builder->icmp(Builder::INT_EQ, $parentObj, $sentinel);
            $fatalBlock = BasicBlockHelper::append($context, 'dom_parent_freed');
            $context->builder->branchIf($isFreed, $fatalBlock, $afterFreed);

            $context->builder->positionAtEnd($fatalBlock);
            self::emitFreedNodeError($context);
            if (!$context->builder->getInsertBlock()->getTerminator()) {
                $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            }
        } else {
            $context->builder->branch($afterFreed);
        }

        $context->builder->positionAtEnd($afterFreed);
        // #27411: synced first/last that exclude this node ⇒ stale parentNode (replaceChild).
        // Unsynced null first+last ⇒ trust parentNode (appendChild may not write firstChild).
        $firstSlot = $objectType->propertySlotFor($parentObj, self::CLASS_ELEMENT, VmDom::PROP_FIRST_CHILD);
        $lastSlot = $objectType->propertySlotFor($parentObj, self::CLASS_ELEMENT, VmDom::PROP_LAST_CHILD);
        $firstPtr = $context->builder->load($firstSlot);
        $lastPtr = $context->builder->load($lastSlot);
        $firstMissing = $context->builder->icmp(Builder::INT_EQ, $firstPtr, $voidPtr->constNull());
        $lastMissing = $context->builder->icmp(Builder::INT_EQ, $lastPtr, $voidPtr->constNull());
        $bothMissing = $context->builder->and($firstMissing, $lastMissing);
        $live = BasicBlockHelper::append($context, 'dom_parent_live');
        $verify = BasicBlockHelper::append($context, 'dom_parent_verify');
        $stale = BasicBlockHelper::append($context, 'dom_parent_stale');
        $context->builder->branchIf($bothMissing, $live, $verify);

        $context->builder->positionAtEnd($verify);
        $firstObj = self::loadChildObjectOrNull($context, $firstPtr, $firstMissing, 'dom_pn_first');
        $lastObj = self::loadChildObjectOrNull($context, $lastPtr, $lastMissing, 'dom_pn_last');
        $isFirst = $context->builder->icmp(Builder::INT_EQ, $firstObj, $obj);
        $isLast = $context->builder->icmp(Builder::INT_EQ, $lastObj, $obj);
        $attached = $context->builder->or($isFirst, $isLast);
        $context->builder->branchIf($attached, $live, $stale);

        $context->builder->positionAtEnd($stale);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($live);
        $declared = self::fetchDeclaredParent($objectType, $obj);
        $declaredPtr = JitValueBox::valuePtrFromVariable($context, $declared);
        $livePred = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($resultTy);
        $phi->addIncoming($nullResult, $nullBlock);
        $phi->addIncoming($nullResult, $parentNullBlock);
        $phi->addIncoming($nullResult, $stale);
        $phi->addIncoming($declaredPtr, $livePred);

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $phi)
        );
    }

    private static function boxNullPtr(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    /** Load __object__* from a firstChild/lastChild VALUE slot, or null when missing. */
    private static function loadChildObjectOrNull(
        Context $context,
        Value $slotPtr,
        Value $isMissing,
        string $label
    ): Value {
        $objPtrTy = $context->getTypeFromString('__object__*');
        $nullObj = $objPtrTy->constNull();
        $pred = $context->builder->getInsertBlock();
        $readBlock = BasicBlockHelper::append($context, $label.'_read');
        $mergeBlock = BasicBlockHelper::append($context, $label.'_merge');
        $context->builder->branchIf($isMissing, $mergeBlock, $readBlock);

        $context->builder->positionAtEnd($readBlock);
        $asValue = $context->builder->pointerCast(
            $slotPtr,
            $context->getTypeFromString('__value__*')
        );
        $read = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $asValue
        );
        $readPred = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($nullObj, $pred);
        $phi->addIncoming($read, $readPred);

        return $phi;
    }

    /** Point parentNode at the freed sentinel (php_libxml_node_free_list sibling). */
    public static function markFreed(Context $context, Value $obj): void
    {
        $objectType = $context->type->object;
        self::ensureParentProp($objectType);
        $sentinel = self::ensureSentinel($context);
        $sentJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $sentinel
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $sentJit,
            JITVariable::TYPE_VALUE
        );
    }

    private static function emitFreedNodeError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'Error', self::FREED_MESSAGE);

            return;
        }
        ErrorRaise::emitRaise($context, self::FREED_MESSAGE);
        $abort = $context->module->getNamedFunction('phpc_jit_abort_if_pending_error');
        if (null !== $abort) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
        }
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
    }

    private static function ensureParentProp(Object_ $objectType): void
    {
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($classId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($classId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
    }

    private static function ensureChildLinkProps(Object_ $objectType): void
    {
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
    }

    private static function ensureSentinel(Context $context): Value
    {
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $global = $context->module->getNamedGlobal(self::SENTINEL_GLOBAL);
        if (null === $global) {
            $global = $context->module->addGlobal($objPtrTy, self::SENTINEL_GLOBAL);
            $global->setInitializer($objPtrTy->constNull());
        }
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        $created = $objectType->allocate($classId);
        $objectType->markObjectConstructed($created);
        $loaded = $context->builder->load($global);
        $missing = $context->builder->icmp(Builder::INT_EQ, $loaded, $objPtrTy->constNull());
        $init = BasicBlockHelper::append($context, 'dom_freed_sent_init');
        $have = BasicBlockHelper::append($context, 'dom_freed_sent_have');
        $merge = BasicBlockHelper::append($context, 'dom_freed_sent_merge');
        $context->builder->branchIf($missing, $init, $have);
        $context->builder->positionAtEnd($init);
        $context->builder->store($created, $global);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($have);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $context->builder->load($global);
    }

    private static function fetchDeclaredParent(Object_ $objectType, Value $obj): JITVariable
    {
        self::ensureParentProp($objectType);

        return ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_ELEMENT,
            VmDom::PROP_PARENT_NODE,
            $objectType->lookup(self::CLASS_ELEMENT)
        );
    }
}
