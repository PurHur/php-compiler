<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for live DOMNodeList::$length in user-script AOT (#18478, #19208). */
final class JitDomNodeListLength
{
    private const CLASS_NODELIST = 'DOMNodeList';

    private const PROP_LENGTH = 'length';

    public static function isDomNodeListLength(string $classLc, string $propLc): bool
    {
        return 'domnodelist' === strtolower($classLc) && self::PROP_LENGTH === strtolower($propLc);
    }

    public static function fetch(Object_ $objectType, Value $obj, ?JITVariable $receiverVar = null): JITVariable
    {
        $knownLen = $receiverVar?->compileTimeDomNodeListLength ?? null;
        if (null !== $knownLen) {
            $context = $objectType->jitContext();
            $i64 = $context->getTypeFromString('int64');
            $nativeSlot = BasicBlockHelper::entryAlloca($context, $i64);
            $context->builder->store($i64->constInt($knownLen, false), $nativeSlot);

            return new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VARIABLE,
                $nativeSlot
            );
        }

        $attrLen = self::tryCompileTimeAttrChildNodesLength($objectType, $receiverVar);
        if (null !== $attrLen) {
            return $attrLen;
        }

        $context = $objectType->jitContext();
        // GLOBAL_COUNT is only valid for getElementsByTagName / XPath snapshot lists,
        // not for childNodes lists which have their own per-instance length slot.
        // A childNodes list has PROP_CHILD_NODES_OWNER set; check at runtime to
        // avoid reading the wrong count after XPath rewrites the global (#32620).
        // Live getElementsByTagName length uses GLOBAL_COUNT whenever the global exists;
        // do not gate on lastLoadWasPureUserScript() — appendChild may run after other
        // DOM ops that clear that compile-time flag while the global stays live (#28605).
        if (JitDomDocumentMethodKernel::shouldUse($context)
            && null !== $context->module->getNamedGlobal(DomUserScriptLiveTagListLlvm::GLOBAL_COUNT)
        ) {
            $classId = $objectType->lookup(self::CLASS_NODELIST);
            if (!$objectType->hasProperty($classId, VmDom::PROP_CHILD_NODES_OWNER)) {
                $objectType->defineProperty($classId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
            }
            $ownerSlot = $objectType->propertySlotFor($obj, self::CLASS_NODELIST, VmDom::PROP_CHILD_NODES_OWNER);
            $ownerValuePtrRaw = $context->builder->load($ownerSlot);
            $voidPtr = $context->getTypeFromString('void*');
            $valuePtrTy = $context->getTypeFromString('__value__*');
            $objPtrTy = $context->getTypeFromString('__object__*');
            // childNodes lists store an owner DOM node in the slot; tag-name lists only
            // get an empty __value__ box from initEmptyValueProperties (#28605).
            $slotNull = $context->builder->icmp(
                Builder::INT_EQ,
                $ownerValuePtrRaw,
                $voidPtr->constNull()
            );
            $bbCheckOwnerObj = BasicBlockHelper::append($context, 'dom_nll_chk_owner_obj');
            $bbGlobal = BasicBlockHelper::append($context, 'dom_nll_global');
            $context->builder->branchIf($slotNull, $bbGlobal, $bbCheckOwnerObj);

            $context->builder->positionAtEnd($bbCheckOwnerObj);
            $ownerObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $context->builder->pointerCast($ownerValuePtrRaw, $valuePtrTy)
            );
            $hasOwner = $context->builder->icmp(
                Builder::INT_NE,
                $ownerObj,
                $objPtrTy->constNull()
            );
            $bbInstance = BasicBlockHelper::append($context, 'dom_nll_instance');
            $bbMerge = BasicBlockHelper::append($context, 'dom_nll_merge');
            $context->builder->branchIf($hasOwner, $bbInstance, $bbGlobal);

            $context->builder->positionAtEnd($bbInstance);
            $instanceLen = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $obj,
                self::CLASS_NODELIST,
                self::PROP_LENGTH,
                $classId
            );
            $instanceVal = $context->helper->loadValue($instanceLen);
            $context->builder->branch($bbMerge);

            $context->builder->positionAtEnd($bbGlobal);
            $globalVal = DomUserScriptLiveTagListLlvm::readStoredCount($context);
            $context->builder->branch($bbMerge);

            $context->builder->positionAtEnd($bbMerge);
            $i64 = $context->getTypeFromString('int64');
            $phi = $context->builder->phi($i64);
            $phi->addIncoming($instanceVal, $bbInstance);
            $phi->addIncoming($globalVal, $bbGlobal);
            // Keep native-long fetch shape aligned with propertyFetchDeclaredSlot(): callers
            // expect TYPE_NATIVE_LONG KIND_VALUE to carry an int64* storage pointer.
            // Returning the raw i64 phi here makes later generic loads treat it as a pointer
            // (load i64), which fails module verification (#32622).
            $nativeSlot = BasicBlockHelper::entryAlloca($context, $i64);
            $context->builder->store($phi, $nativeSlot);

            return new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VARIABLE,
                $nativeSlot
            );
        }

        return ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_NODELIST,
            self::PROP_LENGTH,
            $objectType->lookup(self::CLASS_NODELIST)
        );
    }

    private static function tryCompileTimeAttrChildNodesLength(
        Object_ $objectType,
        ?JITVariable $receiverVar
    ): ?JITVariable {
        if (null === $receiverVar) {
            return null;
        }
        $context = $objectType->jitContext();
        $local = $receiverVar->compileTimeDomAttrLocalName ?? null;
        $ns = $receiverVar->compileTimeDomAttrNamespace ?? '';
        if (null === $local && null !== $receiverVar->objectPropertyReceiverOp) {
            $ownerVar = $context->getVariableFromOpInScopes($receiverVar->objectPropertyReceiverOp);
            $local = $ownerVar->compileTimeDomAttrLocalName ?? null;
            $ns = $ownerVar->compileTimeDomAttrNamespace ?? '';
        }
        if (null === $local) {
            return null;
        }
        $valueLit = JitDomAttrChildEdgeFetch::compileTimeAttrValuePublic($ns, $local);
        if (null === $valueLit) {
            return null;
        }
        $knownLen = '' !== $valueLit ? 1 : 0;
        $i64 = $context->getTypeFromString('int64');
        $nativeSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt($knownLen, false), $nativeSlot);

        return new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VARIABLE,
            $nativeSlot
        );
    }
}
