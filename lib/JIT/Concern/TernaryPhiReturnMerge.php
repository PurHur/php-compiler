<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Ternary / ?: phi operand resolve, return-merge arm helpers, and arm-tail CFG RETURN
 * (#36387 / #8555).
 *
 * Extracted from {@see TernaryJumpIfEchoMerge} so gen-0 split-TU can hollow a smaller TU.
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_compile.c (zend_compile_conditional_expr / zend_compile_return),
 * Zend/zend_vm_def.h (ZEND_JMPZ / ZEND_JMPNZ / ZEND_QM_ASSIGN) — move-only Concern extract;
 * no new C ABI.
 */
trait TernaryPhiReturnMerge
{
    /** True when a ?: arm ASSIGN.arg2 targets $slot (php-cfg phi alias). */
    private function ternaryArmsAssignIntoSlot(?Block $ifBlock, ?Block $elseBlock, int $slot): bool
    {
        foreach ([$ifBlock, $elseBlock] as $branch) {
            if (null === $branch) {
                continue;
            }
            foreach ($branch->opCodes as $branchOp) {
                if (OpCode::TYPE_ASSIGN === $branchOp->type && (int) $branchOp->arg2 === $slot) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Copy a property-backed value into a stack box so CONCAT cannot store back into the
     * live object slot (in-place CONCAT dest === fetch temp; #33849).
     */
    private function detachObjectPropertyStringForConcat(Variable $var): Variable
    {
        if (null === $var->objectPropertySlot && null === $var->objectPropertyName) {
            return $var;
        }

        return $this->reseatPropertyFetchReadIntoValueBox($var);
    }

    /**
     * Non-hashtable property reads must not leave objectPropertySlot on ASSIGN dest —
     * the next `$s = …` would propertyStore into the previous object (#34465 / peer #33849).
     * Hashtable props keep the alias for dim writes (#848).
     */
    private function isScalarObjectPropertyAliasType(?int $propertyType): bool
    {
        if (null === $propertyType) {
            return true;
        }
        if (Variable::TYPE_HASHTABLE === $propertyType) {
            return false;
        }

        return true;
    }

    /**
     * Reseat a non-hashtable property fetch into a stack box and drop the live-slot alias (#34465).
     */
    private function detachScalarObjectPropertyAliasForAssign(Variable $var): Variable
    {
        if (null === $var->objectPropertySlot) {
            return $var;
        }
        if (!$this->isScalarObjectPropertyAliasType($var->objectPropertyType)) {
            return $var;
        }
        $propType = $var->objectPropertyType ?? $var->type;
        if (\in_array($propType, [
            Variable::TYPE_NATIVE_LONG,
            Variable::TYPE_NATIVE_BOOL,
            Variable::TYPE_NATIVE_DOUBLE,
        ], true)) {
            return $this->snapshotNativeScalarPropertyRead($var, $propType);
        }
        $boxed = $this->reseatPropertyFetchReadIntoValueBox($var);
        $boxed->objectPropertySlot = null;
        $boxed->objectPropertyType = null;
        $boxed->objectPropertyReceiver = null;
        $boxed->objectPropertyReceiverOp = null;
        $boxed->objectPropertyName = null;
        $boxed->objectPropertyClassName = null;
        $boxed->objectPropertyDnfArms = null;
        $boxed->objectPropertyClassConstraint = null;
        $boxed->objectPropertyDeclaredTypeLabel = null;
        $boxed->objectPropertyAllowsNull = false;

        return $boxed;
    }

    /**
     * CONCAT operands that are ?: phi aliases must read the stack-phi dest (#32908 / #18052).
     *
     * Do not redirect when this block's CONCAT *defines* $slot (dest === $slot): php-cfg
     * folds `($o->prop . '=')` into in-place CONCAT($slot, $slot, lit) on the true arm —
     * the left operand is the property fetch, not the null-initialized merge phi (#33849).
     */
    private function resolveTernaryPhiConcatOperand(Block $block, int $slot): Operand
    {
        $hasPhiAlias = isset($this->context->coalesceMergeSlotOperands[$slot])
            || isset($this->context->ternaryEchoPhiByAliasSlot[$slot]);
        if ($hasPhiAlias) {
            $definesSlot = false;
            foreach ($block->opCodes as $op) {
                if (
                    OpCode::TYPE_CONCAT === $op->type
                    && null !== $op->arg1
                    && (int) $op->arg1 === $slot
                ) {
                    $definesSlot = true;
                    break;
                }
            }
            if (!$definesSlot) {
                if (isset($this->context->coalesceMergeSlotOperands[$slot])) {
                    return $this->context->coalesceMergeSlotOperands[$slot];
                }

                return $this->context->ternaryEchoPhiByAliasSlot[$slot];
            }
        }
        $op = $block->getOperand($slot);
        assert(null !== $op);

        return $op;
    }

    private function ternaryEchoPhiOperand(Block $mergeBlock, ?Block $ifBlock, ?Block $elseBlock): ?Operand
    {
        $resultSlot = $this->mergeTernaryResultSlot($mergeBlock, $ifBlock, $elseBlock);
        if (null === $resultSlot) {
            return null;
        }
        $mergeIsArgSendPhi = false;
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (
                OpCode::TYPE_ARG_SEND === $mergeOp->type
                && null !== $mergeOp->arg1
                && (int) $mergeOp->arg1 === $resultSlot
            ) {
                $mergeIsArgSendPhi = true;
                break;
            }
        }
        $mergePhi = $mergeIsArgSendPhi ? $mergeBlock->getOperand($resultSlot) : null;
        foreach ([$ifBlock, $elseBlock] as $branch) {
            if (null === $branch) {
                continue;
            }
            foreach ($branch->opCodes as $branchOp) {
                if (OpCode::TYPE_ASSIGN !== $branchOp->type) {
                    continue;
                }
                if ((int) $branchOp->arg2 !== $resultSlot) {
                    continue;
                }

                $armOp = $branch->getOperand($branchOp->arg2);
                // FUNCCALL ARG_SEND merges: arm INIT_ARRAY may mint a distinct Temporary at the
                // phi slot — coalesce must target the merge ARG_SEND operand (#34956).
                // ECHO merges keep the arm operand (#34814 / #32912).
                if (null !== $mergePhi && null !== $armOp && $armOp !== $mergePhi) {
                    return $mergePhi;
                }

                // Prefer the shared phi lvalue (arg2) over the per-arm Assign result temp
                // (arg1). Both ?: arms write arg2; only one arm's arg1 matches the first
                // hit — FuncCall arms then never forceCoalesce into the echo slot and AOT
                // echoes a stale name literal (#34814 / peer #18052 alias redirect).
                return $branch->getOperand($branchOp->arg2);
            }
        }

        return null;
    }

    /**
     * False when a JUMPIF arm has switch/call/echo side effects before its merge JUMP (#878).
     */
    private function branchIsTernaryReturnMergeArm(?Block $branch): bool
    {
        if (null === $branch) {
            return false;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_JUMP === $branchOp->type) {
                break;
            }
            if (
                OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $branchOp->type
                || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $branchOp->type
                || OpCode::TYPE_METHODCALL_INIT === $branchOp->type
                || OpCode::TYPE_STATICCALL_INIT === $branchOp->type
                || OpCode::TYPE_ECHO === $branchOp->type
            ) {
                return false;
            }
        }

        return true;
    }

    private function ternaryReturnPhiOperand(Block $mergeBlock): ?Operand
    {
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (OpCode::TYPE_RETURN === $mergeOp->type && null !== $mergeOp->arg1) {
                return $mergeBlock->getOperand($mergeOp->arg1);
            }
        }

        return null;
    }

    /** True when the branch assigns a string into the shared ?: phi (#8555). */
    private function branchAssignsStringToTernaryPhi(Block $branch, Block $mergeBlock): bool
    {
        $source = $this->ternaryPhiAssignSourceOperand($branch, $mergeBlock);
        if (null === $source) {
            return false;
        }

        return Variable::TYPE_STRING === Variable::getTypeFromType($source->type)
            || $this->operandTypeIncludesString($source);
    }

    /**
     * True when the branch assigns only null into the shared ?: phi (#8555).
     */
    private function branchAssignsOnlyNullToTernaryPhi(Block $branch, Block $mergeBlock): bool
    {
        $source = $this->ternaryPhiAssignSourceOperand($branch, $mergeBlock);
        if (null === $source) {
            return false;
        }

        return Variable::TYPE_NULL === Variable::getTypeFromType($source->type);
    }

    private function ternaryPhiAssignSourceOperand(Block $branch, Block $mergeBlock): ?Operand
    {
        $phi = $this->ternaryReturnPhiOperand($mergeBlock);
        if (null === $phi) {
            return null;
        }
        $phiSlot = $mergeBlock->slotForOperand($phi);
        if (null === $phiSlot) {
            return null;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_ASSIGN !== $branchOp->type) {
                continue;
            }
            // Incomplete ASSIGN operands (NestedJIT VmPregEngine ternaries) — skip (#24115 / #16075).
            $destOp = $branch->getOperand($branchOp->arg1);
            $aliasOp = $branch->getOperand($branchOp->arg2);
            if (null === $destOp && null === $aliasOp) {
                continue;
            }
            $destSlot = null !== $destOp ? $branch->slotForOperand($destOp) : null;
            $aliasSlot = null !== $aliasOp ? $branch->slotForOperand($aliasOp) : null;
            if ($destSlot !== $phiSlot && $aliasSlot !== $phiSlot) {
                continue;
            }

            return $branch->getOperand($branchOp->arg3);
        }

        return null;
    }

    private function operandTypeIncludesString(Operand $op): bool
    {
        $type = $op->type;
        if (null === $type) {
            return false;
        }
        if (\PHPTypes\Type::TYPE_STRING === $type->type) {
            return true;
        }
        foreach ($type->subTypes ?? [] as $sub) {
            if (\PHPTypes\Type::TYPE_STRING === ($sub->type ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** True when the branch only assigns null into the shared ?: phi (#8555). */
    private function branchAssignsNullToTernaryPhi(Block $branch, Block $mergeBlock): bool
    {
        $phi = $this->ternaryReturnPhiOperand($mergeBlock);
        if (null === $phi) {
            return false;
        }
        $phiSlot = $mergeBlock->slotForOperand($phi);
        if (null === $phiSlot) {
            return false;
        }
        if ($this->branchAssignsStringToTernaryPhi($branch, $mergeBlock)) {
            return false;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_ASSIGN !== $branchOp->type) {
                continue;
            }
            $destOp = $branch->getOperand($branchOp->arg1);
            $aliasOp = $branch->getOperand($branchOp->arg2);
            if (null === $destOp && null === $aliasOp) {
                continue;
            }
            $destSlot = null !== $destOp ? $branch->slotForOperand($destOp) : null;
            $aliasSlot = null !== $aliasOp ? $branch->slotForOperand($aliasOp) : null;
            if ($destSlot !== $phiSlot && $aliasSlot !== $phiSlot) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array{0: Block, 1: Block} compile order (non-string arm first)
     */
    private function ternaryReturnMergeCompileOrder(Block $ifBlock, Block $elseBlock, Block $mergeBlock): array
    {
        $ifString = $this->branchAssignsStringToTernaryPhi($ifBlock, $mergeBlock);
        $elseString = $this->branchAssignsStringToTernaryPhi($elseBlock, $mergeBlock);
        if ($ifString && !$elseString) {
            return [$elseBlock, $ifBlock];
        }
        if ($elseString && !$ifString) {
            return [$ifBlock, $elseBlock];
        }

        return [$ifBlock, $elseBlock];
    }

    private function ternaryArmAssignSourceVariable(Block $armBlock, Block $mergeBlock): ?Variable
    {
        $source = $this->ternaryPhiAssignSourceOperand($armBlock, $mergeBlock);
        if (null === $source) {
            return null;
        }
        if (
            Variable::TYPE_NULL === Variable::getTypeFromType($source->type)
            && !$this->operandTypeIncludesString($source)
        ) {
            return null;
        }

        return $this->context->getVariableFromOp($source);
    }


    /**
     * Lower CFG RETURN for a shared ?: phi at an arm tail (issue #8555).
     */
    private function emitCfgReturnOperand(
        PHPLLVM\Value\Function_ $func,
        Block $cfgBlock,
        Operand $returnOperand,
        PHPLLVM\BasicBlock $tailBlock,
        ?Variable $returnValue = null
    ): void {
        if (null !== $tailBlock->getTerminator()) {
            return;
        }
        if (null !== $returnValue) {
            $return = $returnValue;
        } else {
            $bound = $this->context->functionScopeBindingVariable($returnOperand, $cfgBlock);
            if (null !== $bound) {
                $return = $bound;
            } else {
                $return = $this->context->getVariableFromOp($returnOperand);
            }
        }
        $builder = $this->context->builder;
        $builder->positionAtEnd($tailBlock);
        $this->markJitThisConstructedIfLeavingConstruct($cfgBlock);
        if (
            0 === $this->context->inlineIncludeDepth
            && JIT\TryCatchHelper::deferReturnIfNeeded($this, $this->context, $func, $cfgBlock, false, $return)
        ) {
            return;
        }
        if ($cfgBlock->returnTypeVoid) {
            JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
            JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
            JIT\Builtin\TypeErrorRaise::emitRaise(
                $this->context,
                'A void function must not return a value'
            );

            return;
        }
        $return->addref();
        if (null !== $cfgBlock->returnDnfConstraints
            && !JIT\ClassReturnCheck::generatorSkipsBodyReturnCheck($cfgBlock)
        ) {
            JIT\DnfParamCheck::enforce(
                $this->context,
                $return,
                $cfgBlock->returnDnfConstraints,
                'Return value',
                $this->jitReturnTypeCallableName($cfgBlock)
            );
        }
        if (!$this->emitJitClassReturnTypeCheck($cfgBlock, $return)) {
            return;
        }
        if (!$this->emitJitScalarReturnTypeCheck($cfgBlock, $return)) {
            return;
        }
        $retval = $this->context->helper->loadValue($return);
        $expected = $this->cfgFunctionReturnCallbackType($cfgBlock->func);
        if (null === $expected && null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[strtolower($this->context->activeFunction)] ?? null;
        }
        $retval = $this->coerceReturnValue($return, $retval, $expected);
        $retval = $this->alignRetvalToLlvmFnReturn($retval, $func);
        // Arm-tail ?: returns must not use merge-block dead operands — they free branch
        // locals (e.g. string params) before coerceReturnValue finishes (#8555).
        if ($this->isVoidLlvmFunction($func)) {
            $builder->returnVoid();
        } elseif ($this->cfgFunctionReturnsByRef($cfgBlock->func)) {
            $builder->returnValue(
                JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
            );
        } else {
            $builder->returnValue($retval);
        }
    }
}
