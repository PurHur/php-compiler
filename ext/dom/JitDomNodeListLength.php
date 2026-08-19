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

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        // GLOBAL_COUNT is only valid for getElementsByTagName / XPath snapshot lists,
        // not for childNodes lists which have their own per-instance length slot.
        // A childNodes list has PROP_CHILD_NODES_OWNER set; check at runtime to
        // avoid reading the wrong count after XPath rewrites the global (#32620).
        if (JitDomDocumentMethodKernel::shouldUse($context)
            && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
            && null !== $context->module->getNamedGlobal(DomUserScriptLiveTagListLlvm::GLOBAL_COUNT)
        ) {
            $classId = $objectType->lookup(self::CLASS_NODELIST);
            if (!$objectType->hasProperty($classId, VmDom::PROP_CHILD_NODES_OWNER)) {
                $objectType->defineProperty($classId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
            }
            $ownerSlot = $objectType->propertySlotFor($obj, self::CLASS_NODELIST, VmDom::PROP_CHILD_NODES_OWNER);
            $ownerPtr = $context->builder->load($ownerSlot);
            $voidPtr = $context->getTypeFromString('void*');
            $hasOwner = $context->builder->icmp(
                Builder::INT_NE,
                $ownerPtr,
                $voidPtr->constNull()
            );
            $bbInstance = BasicBlockHelper::append($context, 'dom_nll_instance');
            $bbGlobal = BasicBlockHelper::append($context, 'dom_nll_global');
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

            return new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $phi
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
}
