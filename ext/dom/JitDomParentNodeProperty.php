<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * DOMNode::$parentNode for user-script AOT — honor textContent free-list stale markers (#23892).
 *
 * Freed wrappers point parentNode at a module-level sentinel object; reading that
 * raises php-src's dom_objects_not_found() message. Kept-but-detached wrappers
 * keep a null parentNode (Zend: first held child after php_libxml_node_free_list).
 *
 * Reference: php-src ext/dom/php_dom.c dom_objects_not_found() / php_libxml_node_free_list.
 */
final class JitDomParentNodeProperty
{
    private const CLASS_ELEMENT = 'DOMElement';

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
        if (!JitDomDocumentMethodKernel::shouldUse($context)
            || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
        ) {
            return self::fetchDeclaredParent($objectType, $obj);
        }

        self::ensureParentProp($objectType);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_parent_fetch');
        $slot = $objectType->propertySlotFor($obj, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE);
        $slotPtr = $context->builder->load($slot);
        $voidPtr = $context->getTypeFromString('void*');
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
        $sentinel = self::ensureSentinel($context);
        $isFreed = $context->builder->icmp(Builder::INT_EQ, $parentObj, $sentinel);
        $fatalBlock = BasicBlockHelper::append($context, 'dom_parent_freed');
        $okBlock = BasicBlockHelper::append($context, 'dom_parent_ok');
        $context->builder->branchIf($isFreed, $fatalBlock, $okBlock);

        $context->builder->positionAtEnd($fatalBlock);
        self::emitFreedNodeError($context);
        // emitFreedNodeError terminates via catch dispatch or abort. Do not connect
        // this path to $merge → fetchDeclaredParent (that returned the freed sentinel
        // as the property value and broke thin-AOT catch / getMessage; #25475).
        if (!$context->builder->getInsertBlock()->getTerminator()) {
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return self::fetchDeclaredParent($objectType, $obj);
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
