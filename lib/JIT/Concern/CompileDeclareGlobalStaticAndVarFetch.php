<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPTypes\Type;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Global / function-static / variable-variable opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_DECLARE_GLOBAL},
 * {@code TYPE_DECLARE_FUNCTION_STATIC}, {@code TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED},
 * {@code TYPE_FUNCTION_STATIC_INIT_STORE}, and {@code TYPE_VAR_FETCH}. Move-only;
 * no IR shape change.
 *
 * php-src: Zend/zend_compile.c (zend_compile_global_var / zend_compile_static_var),
 * Zend/zend_execute.c (ZEND_FETCH_STATIC / ZEND_BIND_GLOBAL), Zend/zend_vm_def.h
 * (ZEND_FETCH_W / ZEND_FETCH_R for $${name}) — move-only Concern extract; no new C ABI.
 */
trait CompileDeclareGlobalStaticAndVarFetch
{
    /**
     * @param Variable ...$args
     */
    private function compileDeclareGlobalStaticAndVarFetchOp(
        Block $block,
        OpCode $op,
        int $i,
        PHPLLVM\Value $func,
        PHPLLVM\BasicBlock $basicBlock,
        PHPLLVM\Builder $builder,
        Variable ...$args
    ): void {
        switch ($op->type) {
            case OpCode::TYPE_DECLARE_GLOBAL:
                if (!isset($block->constants[$op->arg2])) {
                    throw new \LogicException('Global name must be a compile-time constant');
                }
                $globalName = $block->constants[$op->arg2]->toString();
                $globalVar = $this->ensureJitGlobal($globalName);
                $this->context->jitImportedGlobalNames[$globalName] = true;
                $this->context->bindVariableByName($globalName, $globalVar);
                $destOp = $block->getOperand($op->arg1);
                $this->context->setVariableOp($destOp, $globalVar);
                $globalSlot = $block->slotForOperand($destOp);
                if (null !== $globalSlot) {
                    foreach ($block->scopedOperands() as $scopeOp) {
                        if ($block->slotForOperand($scopeOp) === $globalSlot) {
                            $this->context->setVariableOp($scopeOp, $globalVar);
                        }
                    }
                }
                break;
            case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
                if (!isset($block->constants[$op->arg2])) {
                    throw new \LogicException('Function static key must be a compile-time constant');
                }
                $storageKey = $block->constants[$op->arg2]->toString();
                $destOp = $block->getOperand($op->arg1);
                if (!$this->context->hasVariableOp($destOp)) {
                    $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                }
                $staticVar = $this->ensureJitFunctionStatic($storageKey);
                if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                    $staticDefaultVm = $block->constants[$op->arg3];
                    // php-cfg often mistypes function-static defaults (string[] as string,
                    // or leaves string defaults CFG-unknown). Retype so FETCH_DIM_W picks
                    // HT vs ValueBoxDimWrite correctly (#32800 / #32806 / #32814 / #32830).
                    if (\PHPCompiler\VM\Variable::TYPE_ARRAY === $staticDefaultVm->type) {
                        $this->retypeFunctionStaticOperand($block, $destOp, new Type(Type::TYPE_ARRAY));
                    } elseif (\PHPCompiler\VM\Variable::TYPE_STRING === $staticDefaultVm->type) {
                        $this->retypeFunctionStaticOperand($block, $destOp, Type::string());
                    }
                    \PHPCompiler\JIT\FunctionStaticHelper::emitLazyInit(
                        $this->context,
                        $storageKey,
                        $staticVar,
                        $this->jitVariableFromVmConstant($staticDefaultVm)
                    );
                }
                $this->context->setVariableOp($destOp, $staticVar);
                // Function-static CVs are always defined once DECLARE runs (lazy or runtime
                // init completed on this path) — quiet ZEND_CHECK_UNDEFINED_VAR (#35665).
                \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned($this->context, $destOp, $staticVar);
                $staticName = \PHPCompiler\JIT\OperandName::resolve($destOp);
                if (null !== $staticName && '' !== $staticName) {
                    $this->context->bindVariableByName($staticName, $staticVar);
                }
                $staticSlot = $block->slotForOperand($destOp);
                if (null !== $staticSlot) {
                    foreach ($block->scopedOperands() as $scopeOp) {
                        if ($block->slotForOperand($scopeOp) === $staticSlot) {
                            $this->context->setVariableOp($scopeOp, $staticVar);
                        }
                    }
                }
                break;
            case OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED:
                if (!isset($block->constants[$op->arg2])) {
                    throw new \LogicException('Function static key must be a compile-time constant');
                }
                $branchBlock = $builder->getInsertBlock();
                $builder->positionAtEnd($branchBlock);
                $jumpKey = $block->constants[$op->arg2]->toString();
                $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                $skipEntry = $this->jitBranchEntryBlock($op->block1, $func);
                $initPathBb = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'fn_static_init_path');
                $builder->positionAtEnd($branchBlock);
                $builder->branchIf(
                    \PHPCompiler\JIT\FunctionStaticHelper::isInitializedCondition($this->context, $jumpKey),
                    $skipEntry,
                    $initPathBb
                );
                $builder->positionAtEnd($initPathBb);
                break;
            case OpCode::TYPE_FUNCTION_STATIC_INIT_STORE:
                if (!isset($block->constants[$op->arg2])) {
                    throw new \LogicException('Function static key must be a compile-time constant');
                }
                if (null === $op->arg3) {
                    throw new \LogicException('Function static init store requires a value slot');
                }
                $storeKey = $block->constants[$op->arg2]->toString();
                $storeVar = $this->ensureJitFunctionStatic($storeKey);
                $initValue = $this->variableFromBlockSlot($block, (int) $op->arg3);
                \PHPCompiler\JIT\FunctionStaticHelper::emitRuntimeInitStore(
                    $this->context,
                    $storeKey,
                    $storeVar,
                    $initValue
                );
                break;
            case OpCode::TYPE_VAR_FETCH:
                $destOp = $block->getOperand($op->arg1);
                if (!$this->context->hasVariableOp($destOp)) {
                    $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                }
                $nameSlot = (int) $op->arg2;
                foreach ($block->scopedOperands() as $slotOp) {
                    if ($block->slotForOperand($slotOp) === $nameSlot && !$this->context->hasVariableOp($slotOp)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $slotOp);
                    }
                }
                $nameVar = $this->variableFromBlockSlot($block, $nameSlot);
                $this->foldVarFetchNameFromAssign($block, $nameSlot, $nameVar);
                $forWrite = $this->varFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1);
                $target = \PHPCompiler\JIT\VarFetchHelper::resolveTarget($this->context, $block, $nameVar, $forWrite);
                if ($forWrite) {
                    $this->context->setVariableOp($destOp, $target);
                } else {
                    $this->assignOperand($destOp, $target, true);
                }
                break;
            default:
                throw new \LogicException('Unexpected opcode in compileDeclareGlobalStaticAndVarFetchOp: '.$op->type);
        }
    }
}
