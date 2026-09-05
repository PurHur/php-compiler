<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Foreach iterator opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_ITER_RESET},
 * {@code TYPE_ITER_VALID}, {@code TYPE_ITER_KEY}, {@code TYPE_ITER_VALUE}.
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_FE_RESET_R / ZEND_FE_RESET_RW /
 * ZEND_FE_FETCH_R / ZEND_FE_FETCH_RW), Zend/zend_generators.c —
 * move-only Concern extract; no new C ABI.
 */
trait CompileIter
{
    private function compileIterOp(Block $block, OpCode $op): void
    {
        switch ($op->type) {
            case OpCode::TYPE_ITER_RESET:
                $arrayOp = $block->getOperand($op->arg1);
                $array = $this->context->getVariableFromOp($arrayOp);
                // Zend FE_RESET / CV fetch: Undefined variable E_WARNING before type check (#26148).
                \PHPCompiler\JIT\UndefinedVariableHelper::guardBeforeRuntimeRead($this->context, $arrayOp, $array);
                \PHPCompiler\JIT\GeneratorHelper::hydrateGeneratorMetadata($this->context, $array);
                if (\PHPCompiler\JIT\GeneratorHelper::isGeneratorVariable($array)) {
                    \PHPCompiler\JIT\GeneratorHelper::compileIterReset($this->context, $array);
                    break;
                }
                \PHPCompiler\JIT\IteratorHelper::compileReset(
                    $this->context,
                    $array,
                    self::foreachContainerUserType($arrayOp, $array)
                );
                break;
            case OpCode::TYPE_ITER_VALID:
                $arrayOp = $block->getOperand($op->arg2);
                $array = $this->context->getVariableFromOp($arrayOp);
                \PHPCompiler\JIT\GeneratorHelper::hydrateGeneratorMetadata($this->context, $array);
                if (\PHPCompiler\JIT\GeneratorHelper::isGeneratorVariable($array)) {
                    $valid = \PHPCompiler\JIT\GeneratorHelper::compileIterValid($this->context, $array);
                    $this->assignOperandValue($block->getOperand($op->arg1), $valid);
                    break;
                }
                $valid = \PHPCompiler\JIT\IteratorHelper::compileValid(
                    $this->context,
                    $array,
                    self::foreachContainerUserType($arrayOp, $array)
                );
                $this->assignOperandValue($block->getOperand($op->arg1), $valid);
                break;
            case OpCode::TYPE_ITER_KEY:
                $arrayOp = $block->getOperand($op->arg2);
                $array = $this->context->getVariableFromOp($arrayOp);
                \PHPCompiler\JIT\GeneratorHelper::hydrateGeneratorMetadata($this->context, $array);
                if (\PHPCompiler\JIT\GeneratorHelper::isGeneratorVariable($array)) {
                    $key = \PHPCompiler\JIT\GeneratorHelper::compileIterKey($this->context, $array);
                    $this->assignOperand($block->getOperand($op->arg1), $key);
                    break;
                }
                $key = \PHPCompiler\JIT\IteratorHelper::compileKey(
                    $this->context,
                    $array,
                    self::foreachContainerUserType($arrayOp, $array)
                );
                $this->assignOperand($block->getOperand($op->arg1), $key);
                $arraySlot = $block->slotForOperand($arrayOp);
                if (null !== $arraySlot) {
                    $this->context->foreachPendingKeyByArraySlot[$arraySlot] = $key;
                }
                break;
            case OpCode::TYPE_ITER_VALUE:
                $arrayOp = $block->getOperand($op->arg2);
                $array = $this->context->getVariableFromOp($arrayOp);
                \PHPCompiler\JIT\GeneratorHelper::hydrateGeneratorMetadata($this->context, $array);
                if (\PHPCompiler\JIT\GeneratorHelper::isGeneratorVariable($array)) {
                    if ($op->arg3) {
                        $value = \PHPCompiler\JIT\GeneratorHelper::compileIterValueByRef($this->context, $array, $this);
                        $this->context->setVariableOp($block->getOperand($op->arg1), $value);
                        break;
                    }
                    $value = \PHPCompiler\JIT\GeneratorHelper::compileIterValue($this->context, $array);
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                }
                if ($op->arg3) {
                    $destOp = $block->getOperand($op->arg1);
                    $destName = \PHPCompiler\JIT\OperandName::resolve($destOp);
                    if (null !== $destName) {
                        $this->context->foreachByRefLocalNames[
                            $this->context->resolveRefAliasName($destName)
                        ] = true;
                    }
                    $value = \PHPCompiler\JIT\IteratorHelper::compileValueByRef(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp, $array),
                        $this
                    );
                    $this->context->setVariableOp($destOp, $value);
                    if (null !== $destName) {
                        $this->context->bindVariableByName($destName, $value);
                        \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                            $this->context,
                            $destOp,
                            $value
                        );
                    }
                    break;
                }
                $value = \PHPCompiler\JIT\IteratorHelper::compileValue(
                    $this->context,
                    $array,
                    self::foreachContainerUserType($arrayOp, $array)
                );
                $destOp = $block->getOperand($op->arg1);
                $this->assignOperand($destOp, $value);
                $this->reattachForeachIterClosureInvokeMetadata($block, $arrayOp, $destOp, $value);
                break;
            default:
                throw new \LogicException('compileIterOp: unexpected opcode '.$op->type);
        }
    }
}
