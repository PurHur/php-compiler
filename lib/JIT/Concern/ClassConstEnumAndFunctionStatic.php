<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\JIT\Variable;

/**
 * Class-const / enum seed and function-static init (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code jitClassConstDefineValue}
 * through {@code initJitFunctionStaticValueGlobal} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c / Zend/zend_API.c class constant and static
 * local init paths; enum case binding — move-only Concern extract; no new C
 * ABI and no opcode/IR shape change.
 */
trait ClassConstEnumAndFunctionStatic
{
    /**
     * Resolve a class constant initializer for JIT defineClassConst (#4900, zend_constants.c).
     */
    private function jitClassConstDefineValue(
        Block $block,
        OpCode $op,
        string $constNameLc,
        int $classId,
        ?string $constDisplayName = null
    ): VM\Variable {
        if (
            !isset($block->constants[$op->arg2])
            || $block->constants[$op->arg2]->is(VM\Variable::TYPE_NULL)
        ) {
            $vm = new VM($this->context->runtime->vmContext);
            $className = $this->context->type->object->classNameForId($classId);
            $rootBlock = $this->context->jitFunctionRootBlock ?? $this->context->jitEnclosingBlock;
            VM\ClassConstMaterializer::seedReferencedClasses($vm, $rootBlock, $block, $op->arg2);
            $value = VM\ClassConstMaterializer::materializeSlot($vm, $block, $op->arg2, $className);
        } else {
            $value = $block->constants[$op->arg2];
        }
        if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
            $check = new VM\Variable();
            $check->copyFrom($value);
            $className = $this->context->type->object->classNameForId($classId);
            VM\TypeCheck::assertClassConstantTypedValue(
                $check,
                $block->constants[$op->arg3],
                $constDisplayName ?? $constNameLc,
                '' !== $className ? $className : null
            );
            $value = $check;
        }

        return $value;
    }

    /**
     * Compile DECLARE_ENUM ops in $block that have not been registered yet (#31967).
     */
    private function jitCompilePendingEnumsInBlock(Block $block): void
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_ENUM !== $op->type) {
                continue;
            }
            $nameOp = $block->getOperand($op->arg1);
            if (!$nameOp instanceof Operand\Literal) {
                continue;
            }
            if ($this->context->type->object->isRegisteredEnumLc(strtolower($nameOp->value))) {
                continue;
            }
            $this->jitCompileDeclareEnum($block, $op);
        }
    }

    private function jitCompileDeclareEnum(Block $block, OpCode $op): void
    {
        $nameOp = $this->jitResolveClassLikeDeclareNameOperand($block, $op);
        if (null === $nameOp) {
            return;
        }
        if ($this->emitDuplicateClassLikeDeclareFatalIfNeeded($op, $block, 'enum', $nameOp->value)) {
            return;
        }
        if ([] !== $op->classImplements) {
            JIT\ImplementsHierarchyJitGuard::emitBeforeDeclare(
                $this->context,
                $nameOp->value,
                $op->classImplements,
                $block->scriptPath(),
                $op->sourceLocation,
                null,
                true
            );
        }
        $this->context->pushScope();
        $this->context->scope->classId = $this->context->type->object->declareEnum($nameOp);
        $this->context->type->object->setClassSourceLocation(
            $this->context->scope->classId,
            $op->sourceLocation
        );
        $this->context->scope->className = strtolower($nameOp->value);
        if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
            $this->context->type->object->markAttributeClass($nameOp->value);
        }
        if (null !== $op->arg2 && isset($block->constants[$op->arg2])) {
            $this->context->type->object->setEnumBackedType(
                $this->context->scope->classId,
                $block->constants[$op->arg2]->toString()
            );
        }
        if (null !== $this->context->runtime->vmContext) {
            $this->context->runtime->vmContext->enums[strtolower($nameOp->value)] = true;
        }
        $this->compileClass($op->block1, $this->context->scope->classId);
        if ([] !== $op->classImplements) {
            $this->context->type->object->setClassInterfaces(
                $nameOp->value,
                $op->classImplements
            );
            $this->seedVmClassEntryInterfaces($nameOp->value, $op->classImplements);
        }
        $this->context->type->object->inheritInterfaceConstants(
            $this->context->scope->classId,
            $nameOp->value
        );
        $this->context->type->object->finishEnumClass($this->context->scope->classId);
        $this->seedVmEnumForCompileTimeFolds($nameOp->value, $op, $this->context->scope->classId);
        $this->context->popScope();
    }

    /**
     * Register enum methods/interfaces on vmContext for compile-time json_encode folds (#6880).
     *
     * MODE_AOT skips VM DECLARE_CLASS, so JsonSerializable::jsonSerialize is otherwise
     * unreachable during {@see JitJsonEncode::tryFoldEnumCase}.
     */
    private function seedVmEnumForCompileTimeFolds(string $enumName, OpCode $op, int $classId): void
    {
        $vmContext = $this->context->runtime->vmContext ?? null;
        if (null === $vmContext || null === $op->block1) {
            return;
        }
        $lc = strtolower(ltrim($enumName, '\\'));
        if (!isset($vmContext->classes[$lc])) {
            $vmContext->classes[$lc] = new VM\ClassEntry(ltrim($enumName, '\\'));
        }
        $entry = $vmContext->classes[$lc];
        $entry->isEnum = true;
        $entry->backedType = $this->context->type->object->enumBackedTypeFor($classId);
        if ([] !== $op->classImplements) {
            $entry->interfaces = $op->classImplements;
        }
        VM\EnumSupport::ensureBuiltinEnumInterfaces($entry);
        $bodyBlock = $op->block1;
        $frame = $bodyBlock->getFrame($vmContext);
        foreach ($bodyBlock->opCodes as $methodOp) {
            if (OpCode::TYPE_DECLARE_METHOD !== $methodOp->type || null === $methodOp->block1) {
                continue;
            }
            $methodName = strtolower($frame->scope[$methodOp->arg1]->toString());
            if (isset($entry->methods[$methodName])) {
                continue;
            }
            $method = new Func\PHP($entry->name.'::'.$methodName, $methodOp->block1);
            $entry->methods[$methodName] = $method;
        }
    }

    /**
     * Replace hoisted null placeholders with enum-case singletons once the enum exists (#31967).
     *
     * php-cfg folds `C::K` / `E::X` into Block::$constants (vm type 9) and drops the
     * CLASS_CONST_FETCH opcode, so makeVariableFromOp must rematerialize after DECLARE_ENUM.
     */
    private function rebindEnumCaseConstantSlots(Block $block, OpCode $op): void
    {
        foreach ([$op->arg1, $op->arg2, $op->arg3] as $slot) {
            if (null === $slot || !isset($block->constants[$slot])) {
                continue;
            }
            $vm = $block->constants[$slot];
            if (VM\Variable::TYPE_ENUM_CASE !== $vm->type) {
                continue;
            }
            $operand = $block->getOperand((int) $slot);
            if (null === $operand) {
                continue;
            }
            try {
                $this->context->scope->variables[$operand] = JIT\VmConstantJit::toVariable($this->context, $vm);
            } catch (\LogicException) {
                continue;
            }
        }
    }

    /**
     * Attach implements[] on the VM ClassEntry before compiling class-const expressions (#31967).
     *
     * @param list<string> $implements
     */
    private function seedVmClassEntryInterfaces(string $className, array $implements): void
    {
        $vmContext = $this->context->runtime->vmContext ?? null;
        if (null === $vmContext) {
            return;
        }
        $lc = strtolower(ltrim($className, '\\'));
        if (!isset($vmContext->classes[$lc])) {
            $vmContext->classes[$lc] = new VM\ClassEntry($className);
        }
        $vmContext->classes[$lc]->interfaces = $implements;
    }

    private function jitVariableFromVmConstant(VM\Variable $vm): Variable {
        return JIT\VmConstantJit::toVariable($this->context, $vm);
    }

    private function jitNullVariable(): Variable
    {
        $slot = JIT\JitValueBox::alloc($this->context);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            JIT\JitValueBox::pointer($this->context, $slot)
        );

        return new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    private function jitVariableFromVmArray(VM\Variable $vm): Variable
    {
        return JIT\VmConstantJit::toVariable($this->context, $vm);
    }

    /**
     * Resolve the JIT variable for a scope slot (issue #1226).
     *
     * TYPE_VAR_FETCH arg2 is the slot holding the runtime name string, which may map to
     * multiple CFG operands; prefer a bound operand with compile-time string metadata.
     */
    private function variableFromBlockSlot(Block $block, int $slot): Variable
    {
        $operands = [];
        foreach ($block->scopedOperands() as $op) {
            if ($block->slotForOperand($op) === $slot) {
                $operands[] = $op;
            }
        }
        if ([] === $operands) {
            throw new \LogicException('No operand mapped to slot '.$slot);
        }
        usort($operands, [self::class, 'compareOperandsForSlotResolution']);
        $bound = null;
        foreach ($operands as $op) {
            if (!$this->context->hasVariableOp($op)) {
                continue;
            }
            $candidate = $this->context->getVariableFromOp($op);
            if (null !== $candidate->compileTimeString) {
                return $candidate;
            }
            if (null === $bound) {
                $bound = $candidate;
            }
        }
        if (null !== $bound) {
            return $bound;
        }

        throw new \LogicException('No JIT variable for slot '.$slot);
    }

    /**
     * Collect `global $name` imports before lowering any block — try bodies may compile first (#16828).
     */
    private function prescanFunctionImportedGlobals(\PHPCfg\Func $func): void
    {
        if (null === $func->cfg) {
            return;
        }
        $seen = [];
        $queue = [$func->cfg];
        while ([] !== $queue) {
            $scan = array_shift($queue);
            $id = spl_object_id($scan);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($scan->children as $op) {
                if ($op instanceof \PHPCfg\Op\Terminal\GlobalVar) {
                    $name = JIT\OperandName::resolve($op->var);
                    if (null !== $name && '' !== $name) {
                        $this->context->jitImportedGlobalNames[$name] = true;
                    }
                }
                Cfg\OpSubBlockAccess::enqueueSubBlocks($op, $queue);
            }
        }
    }

    private function ensureJitGlobal(string $name): Variable
    {
        return $this->context->ensureScriptGlobal($name);
    }

    /**
     * Resolve `public const X = SomeEnum::Case` when VM materialization lacks the enum (#4445).
     *
     * @return array{0: int, 1: string}|null enum class id + case key (lowercase)
     */
    private function tryResolveEnumCaseClassConstInit(Block $block, int $valueSlot): ?array
    {
        $fetchOp = null;
        foreach ($block->opCodes as $initOp) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST === $initOp->type && $valueSlot === $initOp->arg2) {
                break;
            }
            if (OpCode::TYPE_CLASS_CONST_FETCH === $initOp->type) {
                $fetchOp = $initOp;
            }
        }
        if (null === $fetchOp) {
            return null;
        }
        $enumClassOp = $block->getOperand($fetchOp->arg2);
        $caseOp = $block->getOperand($fetchOp->arg3);
        $enumName = null;
        $caseName = null;
        if ($enumClassOp instanceof Operand\Literal) {
            $enumName = (string) $enumClassOp->value;
        } elseif (isset($block->constants[$fetchOp->arg2])
            && \PHPCompiler\VM\Variable::TYPE_STRING === $block->constants[$fetchOp->arg2]->type) {
            $enumName = $block->constants[$fetchOp->arg2]->toString();
        }
        if ($caseOp instanceof Operand\Literal) {
            $caseName = (string) $caseOp->value;
        } elseif (isset($block->constants[$fetchOp->arg3])
            && \PHPCompiler\VM\Variable::TYPE_STRING === $block->constants[$fetchOp->arg3]->type) {
            $caseName = $block->constants[$fetchOp->arg3]->toString();
        }
        if (null === $enumName || null === $caseName) {
            return null;
        }
        $enumLc = strtolower(ltrim($enumName, '\\'));
        if (!$this->context->type->object->isRegisteredEnumLc($enumLc)) {
            return null;
        }
        $enumClassId = $this->context->type->object->lookup($enumLc);
        if (!$this->context->type->object->isEnumClassId($enumClassId)) {
            return null;
        }

        return [$enumClassId, \PHPCompiler\ClassConstName::key($caseName)];
    }

    /**
     * Map a folded TYPE_ENUM_CASE VM slot to the JIT enum singleton (#31967).
     *
     * @return array{0: int, 1: string}|null
     */
    private function tryEnumCaseRefFromVmConstant(VM\Variable $vm): ?array
    {
        if (VM\Variable::TYPE_ENUM_CASE !== $vm->type) {
            return null;
        }
        $case = $vm->toEnumCase();
        $enumLc = strtolower(ltrim($case->enumClass->name, '\\'));
        if (!$this->context->type->object->hasDeclaredClass($enumLc)
            || !$this->context->type->object->isRegisteredEnumLc($enumLc)) {
            return null;
        }
        $enumClassId = $this->context->type->object->lookup($enumLc);
        if (!$this->context->type->object->isEnumClassId($enumClassId)) {
            return null;
        }

        return [$enumClassId, \PHPCompiler\ClassConstName::key($case->caseName)];
    }

    private function ensureJitFunctionStatic(string $storageKey): Variable
    {
        if (!isset($this->context->jitFunctionStaticVariables[$storageKey])) {
            $globalName = 'phpc_fn_static_val_'.substr(hash('sha256', $storageKey), 0, 16);
            $ptrTy = $this->context->getTypeFromString('__value__*');
            $global = $this->context->module->addGlobal($ptrTy, $globalName);
            $global->setInitializer($ptrTy->constNull());
            $this->initJitFunctionStaticValueGlobal($global);
            $staticVar = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $global
            );
            $staticVar->functionStaticGlobal = true;
            $this->context->jitFunctionStaticVariables[$storageKey] = $staticVar;
        }

        return $this->context->jitFunctionStaticVariables[$storageKey];
    }

    /**
     * Retype a DECLARE_FUNCTION_STATIC operand (and same-slot aliases) so FETCH_DIM_W
     * can distinguish HT vs string-offset paths (#32806 / #32814).
     */
    private function retypeFunctionStaticOperand($block, Operand $destOp, Type $type): void
    {
        $destOp->type = $type;
        $typedSlot = $block->slotForOperand($destOp);
        if (null === $typedSlot) {
            return;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) === $typedSlot) {
                $scopeOp->type = $type;
            }
        }
    }

    private function initJitFunctionStaticValueGlobal(PHPLLVM\Value $global): void
    {
        $restore = $this->context->builder->getInsertBlock();
        $this->context->builder->positionAtEnd($this->context->initBlock);
        $valueType = $this->context->getTypeFromString('__value__');
        $heapVal = $this->context->memory->malloc($valueType);
        $heapPtr = $this->context->builder->pointerCast(
            $heapVal,
            $this->context->getTypeFromString('__value__*')
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            $heapPtr
        );
        $this->context->builder->store($heapPtr, $global);
        $this->context->builder->positionAtEnd($restore);
    }
}
