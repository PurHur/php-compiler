<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmVarFetch;

/**
 * VM TYPE_VAR_FETCH / TYPE_DECLARE_GLOBAL / TYPE_DECLARE_FUNCTION_STATIC /
 * TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED / TYPE_FUNCTION_STATIC_INIT_STORE
 * dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner case bodies
 * (php-src Zend/zend_vm_def.h ZEND_FETCH_W/R/RW / ZEND_BIND_GLOBAL /
 * ZEND_BIND_STATIC; zend_execute.c function static storage). Concern trait —
 * same namespace as parent so relative Frame / OpCode / Block helpers resolve.
 * Function-static storage bridges live in {@see ClosureBindAndFunctionStatic}.
 * Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait VarFetchGlobalAndFunctionStaticDispatch
{
    /**
     * Execute VAR_FETCH / global declare / function-static opcodes.
     *
     * @return Frame|int|null
     */
    private function executeVarFetchGlobalAndFunctionStaticDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_VAR_FETCH:
            $dest = $frame->scope[$op->arg1];
            $nameSlot = (int) $op->arg2;
            $nameHolder = $frame->scope[$nameSlot]->resolveIndirect();
            $nameOperand = $frame->block->operandForScopeSlot($nameSlot);
            $nameVarLabel = null !== $nameOperand ? Block::resolveVariableName($nameOperand) : null;
            if (
                null !== $nameVarLabel
                && (Variable::TYPE_NULL === $nameHolder->type || Variable::TYPE_UNDEFINED === $nameHolder->type)
            ) {
                $this->context->errors->undefinedVariable(
                    $nameVarLabel,
                    $this->context,
                    $frame,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null
                );
            }
            [$name, $catchFrame] = $this->coerceRuntimeOperandToString($nameHolder, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            if ('this' === strtolower($name)) {
                if (null !== $frame->block->func && null !== $frame->block->func->class) {
                    $isStatic = (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
                    $thisIdx = $frame->block->slotIndexForVariableName('this');
                    if ($isStatic || null === $thisIdx || !isset($frame->scope[$thisIdx])) {
                        $catchFrame = $this->dispatchVmError(
                            'Using $this when not in object context',
                            $frame
                        );
                        if (null !== $catchFrame) {
                            return $catchFrame;
                        }

                        return null;
                    }
                }
            }
            $forWrite = $this->varFetchDestUsedAsAssignLvalue($frame, $op);
            if ('' === $name) {
                $dest->indirect(new Variable());

                return null;
            }
            if (VmVarFetch::isSuperglobalName($name)) {
                $target = $this->context->ensureSuperglobal($name);
            } elseif ($forWrite) {
                $target = $frame->block->ensureVariableByRuntimeName($name, $frame);
            } else {
                $target = $frame->block->findVariableByRuntimeName($name, $frame);
                if (null === $target) {
                    $this->context->errors->undefinedVariable(
                        $name,
                        $this->context,
                        $frame,
                        '' !== $frame->scriptPath ? $frame->scriptPath : null
                    );
                    $target = new Variable();
                }
            }
            $dest->indirect($target);

            return null;
        case OpCode::TYPE_DECLARE_GLOBAL:
            if (!isset($frame->block->constants[$op->arg2])) {
                throw new \LogicException('Global name must be a compile-time constant');
            }
            $globalName = $frame->block->constants[$op->arg2]->toString();
            $frame->scope[$op->arg1]->indirect($this->context->ensureGlobal($globalName));
            // Zend: `global $x` installs $x in the active symbol table (compact /
            // get_defined_vars see it). Same as TYPE_DECLARE_FUNCTION_STATIC (#25898).
            $this->markScopeSlotInitialized($frame, (int) $op->arg1);

            return null;
        case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
            if (!isset($frame->block->constants[$op->arg2])) {
                throw new \LogicException('Function static key must be a compile-time constant');
            }
            $storageKey = $frame->block->constants[$op->arg2]->toString();
            $storage = $this->ensureFunctionStaticForFrame($frame, $storageKey);
            if (!$this->isFunctionStaticInitializedForFrame($frame, $storageKey)) {
                if (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                    $storage->copyFrom($frame->block->constants[$op->arg3]);
                    $catchFrame = $this->enforceFunctionStaticWrite(
                        $storage,
                        $frame,
                        $op->functionStaticVarName
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $this->markFunctionStaticInitializedForFrame($frame, $storageKey);
                }
            }
            $this->applyFunctionStaticTypeMetadata($storage, $frame, $op);
            $frame->scope[$op->arg1]->indirect($storage);
            $this->markScopeSlotInitialized($frame, (int) $op->arg1);

            return null;
        case OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED:
            if (!isset($frame->block->constants[$op->arg2])) {
                throw new \LogicException('Function static key must be a compile-time constant');
            }
            $jumpKey = $frame->block->constants[$op->arg2]->toString();
            if ($this->isFunctionStaticInitializedForFrame($frame, $jumpKey)) {
                return $this->frameForBranch($frame, $op->block1);
            }

            return null;
        case OpCode::TYPE_FUNCTION_STATIC_INIT_STORE:
            if (!isset($frame->block->constants[$op->arg2])) {
                throw new \LogicException('Function static key must be a compile-time constant');
            }
            if (null === $op->arg3) {
                throw new \LogicException('Function static init store requires a value slot');
            }
            $storeKey = $frame->block->constants[$op->arg2]->toString();
            $store = $this->ensureFunctionStaticForFrame($frame, $storeKey);
            $this->applyFunctionStaticTypeMetadata($store, $frame, $op);
            $store->copyFrom($frame->scope[$op->arg3]->resolveIndirect());
            $catchFrame = $this->enforceFunctionStaticWrite(
                $store,
                $frame,
                $op->functionStaticVarName
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $this->markFunctionStaticInitializedForFrame($frame, $storeKey);

            return null;
        default:
            throw new \LogicException(
                'VarFetchGlobalAndFunctionStaticDispatch: unexpected opcode '.$op->type
            );
        }
    }

    /** True when the next opcode assigns through this VAR_FETCH destination slot (#3801, #5370). */
    private function varFetchDestUsedAsAssignLvalue(Frame $frame, OpCode $op): bool
    {
        $nextIndex = $frame->pos;
        if ($nextIndex >= $frame->block->nOpCodes) {
            return false;
        }
        $next = $frame->block->opCodes[$nextIndex] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsAssignLvalue($next, (int) $op->arg1);
    }
}
