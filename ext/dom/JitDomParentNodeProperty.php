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
 * and replaceChild detach (#27411 / #34590).
 *
 * Freed wrappers point parentNode at a module-level sentinel object; reading that
 * raises php-src's dom_objects_not_found() message. Kept-but-detached wrappers
 * keep a null parentNode (Zend: first held child after php_libxml_node_free_list).
 *
 * Thin AOT replaceChild updates the parent's firstChild/lastChild but cannot safely
 * null the replaced node's parentNode slot when that node came from appendChild()'s
 * boxed return (heap corruption / #27216). Instead, parentNode reads treat a node as
 * orphaned when its claimed parent has synced children that do not include it.
 *
 * Attachment must walk firstChild→nextSibling — first/last alone falsely orphans
 * middle children (`item(1)` / `nextSibling` in 3+ lists) so `$n->parentNode` is
 * null and `$n->parentNode->replaceChild(...)` aborts (#34590).
 *
 * DOMAttr / Dom\Attr: parentNode is the owner element (php-src attr parent_get).
 * Never GEP DOMElement::$parentNode on an Attr object — that SIGSEGVs (#35227 / re-#20501).
 *
 * Reference: php-src ext/dom/php_dom.c / ext/dom/node.c dom_node_replace_child.
 */
final class JitDomParentNodeProperty
{
    private const CLASS_ELEMENT = 'DOMElement';

    private const CLASS_ATTR = 'DOMAttr';

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

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_parent_fetch');
        $resultTy = $context->getTypeFromString('__value__*');

        // Attr → ownerElement (do not walk Element slots) (#35227 / re-#20501 / #35185).
        $isAttr = JitDomAppendChildLiveSlots::isAttrNode($context, $obj);
        $bbAttr = BasicBlockHelper::append($context, 'dom_parent_attr');
        $bbElem = BasicBlockHelper::append($context, 'dom_parent_elem');
        $bbOut = BasicBlockHelper::append($context, 'dom_parent_out');
        $context->builder->branchIf($isAttr, $bbAttr, $bbElem);

        $context->builder->positionAtEnd($bbAttr);
        $attrParent = self::fetchAttrOwnerAsParent($objectType, $obj);
        $attrPtr = JitValueBox::valuePtrFromVariable($context, $attrParent);
        $attrPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbOut);

        $context->builder->positionAtEnd($bbElem);
        $elemParent = self::fetchElementParentVerified($objectType, $obj);
        $elemPtr = JitValueBox::valuePtrFromVariable($context, $elemParent);
        $elemPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbOut);

        $context->builder->positionAtEnd($bbOut);
        $phi = $context->builder->phi($resultTy);
        $phi->addIncoming($attrPtr, $attrPred);
        $phi->addIncoming($elemPtr, $elemPred);

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $phi)
        );
    }

    /**
     * php-src: Attr parentNode === ownerElement (libxml xmlAttr->parent).
     */
    private static function fetchAttrOwnerAsParent(Object_ $objectType, Value $obj): JITVariable
    {
        $attrClassId = $objectType->lookup(self::CLASS_ATTR);
        if (!$objectType->hasProperty($attrClassId, VmDom::PROP_OWNER_ELEMENT)) {
            $objectType->defineProperty($attrClassId, VmDom::PROP_OWNER_ELEMENT, JITVariable::TYPE_VALUE);
        }
        if ($objectType->hasDeclaredClass('Dom\\Attr')) {
            $livingId = $objectType->lookup('Dom\\Attr');
            if (!$objectType->hasProperty($livingId, VmDom::PROP_OWNER_ELEMENT)) {
                $objectType->defineProperty($livingId, VmDom::PROP_OWNER_ELEMENT, JITVariable::TYPE_VALUE);
            }
        }

        // Runtime class_id selects DOMAttr vs Dom\Attr layout (peer #35185).
        return self::fetchAttrOwnerElementByClassId($objectType, $obj);
    }

    /**
     * Load Attr::$ownerElement using the object's runtime class_id (DOMAttr vs Dom\Attr).
     */
    private static function fetchAttrOwnerElementByClassId(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_attr_owner_fetch');
        $resultTy = $context->getTypeFromString('__value__*');
        $map = $context->structFieldMap['__object__'];
        $classIdVal = $context->builder->load(
            $context->builder->structGep($obj, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $classicId = $objectType->lookup(self::CLASS_ATTR);
        $isClassic = $context->builder->icmp(
            Builder::INT_EQ,
            $classIdVal,
            $i64->constInt($classicId, false)
        );
        $bbClassic = BasicBlockHelper::append($context, 'dom_attr_owner_classic');
        $bbLiving = BasicBlockHelper::append($context, 'dom_attr_owner_living');
        $bbMerge = BasicBlockHelper::append($context, 'dom_attr_owner_merge');
        $context->builder->branchIf($isClassic, $bbClassic, $bbLiving);

        $context->builder->positionAtEnd($bbClassic);
        $classic = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_ATTR,
            VmDom::PROP_OWNER_ELEMENT,
            $classicId
        );
        $classicPtr = JitValueBox::valuePtrFromVariable($context, $classic);
        $classicPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbLiving);
        $livingClass = 'Dom\\Attr';
        $livingId = $objectType->lookup($livingClass);
        if (!$objectType->hasProperty($livingId, VmDom::PROP_OWNER_ELEMENT)) {
            $objectType->defineProperty($livingId, VmDom::PROP_OWNER_ELEMENT, JITVariable::TYPE_VALUE);
        }
        $living = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            $livingClass,
            VmDom::PROP_OWNER_ELEMENT,
            $livingId
        );
        $livingPtr = JitValueBox::valuePtrFromVariable($context, $living);
        $livingPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);
        $phi = $context->builder->phi($resultTy);
        $phi->addIncoming($classicPtr, $classicPred);
        $phi->addIncoming($livingPtr, $livingPred);

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $phi)
        );
    }

    /**
     * Element/Document parentNode with stale-slot / freed-sentinel checks (#27411 / #34590).
     */
    private static function fetchElementParentVerified(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        self::ensureParentProp($objectType);
        self::ensureChildLinkProps($objectType);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_parent_elem_fetch');

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
        // Runtime sentinel compare — markFreed() may run on any user-script textContent detach (#33807).
        $sentinel = self::ensureSentinel($context);
        $isFreed = $context->builder->icmp(Builder::INT_EQ, $parentObj, $sentinel);
        $fatalBlock = BasicBlockHelper::append($context, 'dom_parent_freed');
        $context->builder->branchIf($isFreed, $fatalBlock, $afterFreed);

        $context->builder->positionAtEnd($fatalBlock);
        self::emitFreedNodeError($context);
        if (!$context->builder->getInsertBlock()->getTerminator()) {
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }

        $context->builder->positionAtEnd($afterFreed);
        // #27411 / #34590: synced children that exclude this node ⇒ stale parentNode
        // (replaceChild). Unsynced null first+last ⇒ trust parentNode (appendChild may
        // not write firstChild). Walk the sibling chain — first/last alone orphans
        // middle children (#34590).
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
        $ends = $context->builder->or($isFirst, $isLast);
        $walk = BasicBlockHelper::append($context, 'dom_parent_walk');
        $context->builder->branchIf($ends, $live, $walk);

        $context->builder->positionAtEnd($walk);
        $inChain = self::emitIsInChildChain($context, $objectType, $firstObj, $obj);
        $context->builder->branchIf($inChain, $live, $stale);

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

    /**
     * True when {@code $needle} appears in {@code $first}→nextSibling chain (#34590).
     *
     * Bounded by a compile-time cap so a corrupt cycle cannot hang AOT.
     */
    private static function emitIsInChildChain(
        Context $context,
        Object_ $objectType,
        Value $first,
        Value $needle
    ): Value {
        self::ensureChildLinkProps($objectType);
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_NEXT_SIBLING)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_NEXT_SIBLING, JITVariable::TYPE_VALUE);
        }

        $objPtrTy = $context->getTypeFromString('__object__*');
        $voidPtr = $context->getTypeFromString('void*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $curSlot = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $foundSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $guardSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($first, $curSlot);
        $context->builder->store($i1->constInt(0, false), $foundSlot);
        $context->builder->store($i64->constInt(0, false), $guardSlot);

        $bbHdr = BasicBlockHelper::append($context, 'dom_pn_chain_hdr');
        $bbBody = BasicBlockHelper::append($context, 'dom_pn_chain_body');
        $bbNext = BasicBlockHelper::append($context, 'dom_pn_chain_next');
        $bbEnd = BasicBlockHelper::append($context, 'dom_pn_chain_end');
        $context->builder->branch($bbHdr);

        $context->builder->positionAtEnd($bbHdr);
        $cur = $context->builder->load($curSlot);
        $curNull = $context->builder->icmp(Builder::INT_EQ, $cur, $objPtrTy->constNull());
        $guard = $context->builder->load($guardSlot);
        // Cap: real DOM trees rarely need >4k sibling walks; prevents hang on cycles.
        $tooMany = $context->builder->icmp(Builder::INT_SGE, $guard, $i64->constInt(4096, false));
        $stop = $context->builder->or($curNull, $tooMany);
        $context->builder->branchIf($stop, $bbEnd, $bbBody);

        $context->builder->positionAtEnd($bbBody);
        $hit = $context->builder->icmp(Builder::INT_EQ, $cur, $needle);
        $bbHit = BasicBlockHelper::append($context, 'dom_pn_chain_hit');
        $context->builder->branchIf($hit, $bbHit, $bbNext);

        $context->builder->positionAtEnd($bbHit);
        $context->builder->store($i1->constInt(1, false), $foundSlot);
        $context->builder->branch($bbEnd);

        $context->builder->positionAtEnd($bbNext);
        $context->builder->store(
            $context->builder->add($guard, $i64->constInt(1, false)),
            $guardSlot
        );
        $nextRaw = $context->builder->load(
            $objectType->propertySlotFor($cur, self::CLASS_ELEMENT, VmDom::PROP_NEXT_SIBLING)
        );
        $nextMissing = $context->builder->icmp(Builder::INT_EQ, $nextRaw, $voidPtr->constNull());
        $nextObj = self::loadChildObjectOrNull($context, $nextRaw, $nextMissing, 'dom_pn_chain_nx');
        $context->builder->store($nextObj, $curSlot);
        $context->builder->branch($bbHdr);

        $context->builder->positionAtEnd($bbEnd);

        return $context->builder->load($foundSlot);
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
