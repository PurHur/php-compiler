<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM runFramesInner opcode loop + try/catch resume epilogue (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner (php-src
 * Zend/zend_vm_execute.h execute_ex / ZEND_VM_CONTINUE / ZEND_VM_ENTER /
 * ZEND_VM_LEAVE; Zend/zend_execute.c zend_execute_ex; exception / generator /
 * fiber suspend paths in zend_generators.c / zend_fibers.c / zend_exceptions.c).
 * Concern trait — same namespace as parent so relative Frame / OpCode /
 * dispatch helpers resolve. Move-only; no new C ABI.
 *
 * Control flow uses labeled `goto nextframe` / `goto restart` matching the
 * prior hub method (caller is {@see \PHPCompiler\VM}::runFrames).
 */
trait RunFramesInner
{
    private function runFramesInner(): int
    {
nextframe:
        $frame = $this->context->pop();

        if (is_null($frame)) {
            return self::SUCCESS;
        }
restart:
        $this->popTryHandlerIfAtMergeBlock($frame);
        if ($this->context->pendingReturnDispatch) {
            $this->context->pendingReturnDispatch = false;
            $frame = $this->context->pendingReturnResumeFrame;
            $isVoid = $this->context->pendingReturnIsVoid;
            $returnValue = $this->context->pendingReturnValue;
            $this->clearPendingReturnState();
            $pendingReturnOutcome = $isVoid
                ? $this->completeReturnVoid($frame)
                : $this->completeReturnValue($frame, $returnValue);
            if ($pendingReturnOutcome instanceof Frame) {
                $frame = $pendingReturnOutcome;
                goto restart;
            }
            if (self::RETURN_NEXTFRAME === $pendingReturnOutcome) {
                goto nextframe;
            }

            return $pendingReturnOutcome;
        }

        $this->executingFrame = $frame;
        $limits = $this->context->executionLimits;
        $timerDisabled = $limits->isTimerDisabled();
        // Cache deferred-definitions state: the three arrays are only populated by
        // declaration opcodes (DECLARE_CLASS etc.), so a block containing none will
        // never need the flush. Checking a bool per-op is ~20× cheaper than calling
        // assertDeferredDefinitionsBeforeRuntime() which does three empty-array
        // comparisons plus a method dispatch (#36411 / #36449).
        $hasDeferredDefs = [] !== $this->context->deferredTraitUses
            || [] !== $this->context->deferredClassConstants
            || [] !== $this->context->deferredParentInheritance;

        while ($frame->pos < $frame->block->nOpCodes) {
            if (!$timerDisabled) {
                $limits->check($this->context, $frame);
            }
            $op = $frame->block->opCodes[$frame->pos++];
            if ($hasDeferredDefs) {
                try {
                    $this->assertDeferredDefinitionsBeforeRuntime($op->type);
                } catch (\Error $deferredParentError) {
                    $catchFrame = $this->dispatchVmError($deferredParentError->getMessage(), $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                }
                // Re-check after flush: if all resolved, skip on subsequent ops.
                $hasDeferredDefs = [] !== $this->context->deferredTraitUses
                    || [] !== $this->context->deferredClassConstants
                    || [] !== $this->context->deferredParentInheritance;
            } elseif (
                OpCode::TYPE_DECLARE_CLASS === $op->type
                || OpCode::TYPE_DECLARE_ENUM === $op->type
                || OpCode::TYPE_DECLARE_TRAIT === $op->type
                || OpCode::TYPE_DECLARE_INTERFACE === $op->type
                || OpCode::TYPE_FUNCDEF === $op->type
                || OpCode::TYPE_DECLARE_GLOBAL_CONST === $op->type
            ) {
                // Next non-declaration op in this block must flush (#25627).
                $hasDeferredDefs = true;
            }
            try {
                switch ($op->type) {
                case OpCode::TYPE_TYPE_ASSERT:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg1->copyFrom($arg2); 
                    break;
                case OpCode::TYPE_ASSIGN:
                    $assignOutcome = $this->executeAssignDispatch($frame, $op);
                    if ($assignOutcome instanceof Frame) {
                        $frame = $assignOutcome;
                        goto restart;
                    }
                    if (is_int($assignOutcome)) {
                        return $assignOutcome;
                    }
                    break;
                case OpCode::TYPE_ASSIGN_REF:
                    $assignRefOutcome = $this->executeAssignRefDispatch($frame, $op);
                    if ($assignRefOutcome instanceof Frame) {
                        $frame = $assignRefOutcome;
                        goto restart;
                    }
                    if (is_int($assignRefOutcome)) {
                        return $assignRefOutcome;
                    }
                    break;
                case OpCode::TYPE_VAR_FETCH:
                case OpCode::TYPE_DECLARE_GLOBAL:
                case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
                case OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED:
                case OpCode::TYPE_FUNCTION_STATIC_INIT_STORE:
                    $varFetchGlobalStaticOutcome = $this->executeVarFetchGlobalAndFunctionStaticDispatch($frame, $op);
                    if ($varFetchGlobalStaticOutcome instanceof Frame) {
                        $frame = $varFetchGlobalStaticOutcome;
                        goto restart;
                    }
                    if (is_int($varFetchGlobalStaticOutcome)) {
                        return $varFetchGlobalStaticOutcome;
                    }
                    break;
                case OpCode::TYPE_LIST_UNPACK_CHECK:
                case OpCode::TYPE_LIST_SPREAD_ASSIGN:
                    $listUnpackSpreadOutcome = $this->executeListUnpackAndSpreadAssignDispatch($frame, $op);
                    if ($listUnpackSpreadOutcome instanceof Frame) {
                        $frame = $listUnpackSpreadOutcome;
                        goto restart;
                    }
                    if (is_int($listUnpackSpreadOutcome)) {
                        return $listUnpackSpreadOutcome;
                    }
                    break;
                case OpCode::TYPE_ARRAY_DIM_FETCH:
                case OpCode::TYPE_ARRAY_DIM_FETCH_WRITE:
                    $dimFetchOutcome = $this->executeArrayDimFetchDispatch($frame, $op);
                    if ($dimFetchOutcome instanceof Frame) {
                        $frame = $dimFetchOutcome;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_CAST_BOOL:
                case OpCode::TYPE_CAST_INT:
                case OpCode::TYPE_CAST_FLOAT:
                case OpCode::TYPE_CAST_STRING:
                case OpCode::TYPE_CAST_ARRAY:
                case OpCode::TYPE_CAST_OBJECT:
                case OpCode::TYPE_CAST_UNSET:
                case OpCode::TYPE_CAST_VOID:
                    $castOutcome = $this->executeScalarCastDispatch($frame, $op);
                    if ($castOutcome instanceof Frame) {
                        $frame = $castOutcome;
                        goto restart;
                    }
                    if (is_int($castOutcome)) {
                        return $castOutcome;
                    }
                    break;
                case OpCode::TYPE_IDENTICAL:
                case OpCode::TYPE_NOT_IDENTICAL:
                case OpCode::TYPE_EQUAL:
                case OpCode::TYPE_NOT_EQUAL:
                case OpCode::TYPE_LOGICAL_XOR:
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                case OpCode::TYPE_SPACESHIP:
                    $compareOutcome = $this->executeScalarCompareDispatch($frame, $op);
                    if ($compareOutcome instanceof Frame) {
                        $frame = $compareOutcome;
                        goto restart;
                    }
                    if (is_int($compareOutcome)) {
                        return $compareOutcome;
                    }
                    break;
                case OpCode::TYPE_POST_INC:
                case OpCode::TYPE_PRE_INC:
                case OpCode::TYPE_POST_DEC:
                case OpCode::TYPE_PRE_DEC:
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_POW:
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_UNARY_PLUS:
                case OpCode::TYPE_BITWISE_NOT:
                    $arithOutcome = $this->executeScalarArithBitwiseUnaryDispatch($frame, $op);
                    if ($arithOutcome instanceof Frame) {
                        $frame = $arithOutcome;
                        goto restart;
                    }
                    if (is_int($arithOutcome)) {
                        return $arithOutcome;
                    }
                    break;
                case OpCode::TYPE_CONCAT:
                    $scalarOutcome = $this->executeScalarCastCompareArithConcatDispatch($frame, $op);
                    if ($scalarOutcome instanceof Frame) {
                        $frame = $scalarOutcome;
                        goto restart;
                    }
                    if (is_int($scalarOutcome)) {
                        return $scalarOutcome;
                    }
                    break;
                case OpCode::TYPE_ECHO:
                    $echoOutcome = $this->executeEchoDispatch($frame, $op);
                    if ($echoOutcome instanceof Frame) {
                        $frame = $echoOutcome;
                        goto restart;
                    }
                    if (is_int($echoOutcome)) {
                        return $echoOutcome;
                    }
                    break;
                case OpCode::TYPE_PRINT:
                    $printOutcome = $this->executePrintDispatch($frame, $op);
                    if ($printOutcome instanceof Frame) {
                        $frame = $printOutcome;
                        goto restart;
                    }
                    if (is_int($printOutcome)) {
                        return $printOutcome;
                    }
                    break;
                case OpCode::TYPE_EVAL:
                    $evalOutcome = $this->executeEvalDispatch($frame, $op);
                    if ($evalOutcome instanceof Frame) {
                        $frame = $evalOutcome;
                        goto restart;
                    }
                    if (is_int($evalOutcome)) {
                        return $evalOutcome;
                    }
                    break;
                case OpCode::TYPE_COALESCE:
                    $coalesceOutcome = $this->executeCoalesceDispatch($frame, $op);
                    if ($coalesceOutcome instanceof Frame) {
                        $frame = $coalesceOutcome;
                        goto restart;
                    }
                    if (is_int($coalesceOutcome)) {
                        return $coalesceOutcome;
                    }
                    break;
                case OpCode::TYPE_NULLSAFE:
                    $nullsafeOutcome = $this->executeNullsafeDispatch($frame, $op);
                    if ($nullsafeOutcome instanceof Frame) {
                        $frame = $nullsafeOutcome;
                        goto restart;
                    }
                    if (is_int($nullsafeOutcome)) {
                        return $nullsafeOutcome;
                    }
                    break;
                case OpCode::TYPE_BEGIN_SILENCE:
                    $this->executeBeginSilenceDispatch($frame, $op);
                    break;
                case OpCode::TYPE_END_SILENCE:
                    $this->executeEndSilenceDispatch($frame, $op);
                    break;
                case OpCode::TYPE_EXIT:
                    $exitOutcome = $this->executeExitDispatch($frame, $op);
                    if ($exitOutcome instanceof Frame) {
                        $frame = $exitOutcome;
                        goto restart;
                    }
                    if (is_int($exitOutcome)) {
                        return $exitOutcome;
                    }
                    break;
                case OpCode::TYPE_JUMP:
                    $jumpOutcome = $this->executeJumpDispatch($frame, $op);
                    if ($jumpOutcome instanceof Frame) {
                        $frame = $jumpOutcome;
                        goto restart;
                    }
                    if (is_int($jumpOutcome)) {
                        return $jumpOutcome;
                    }
                    break;
                case OpCode::TYPE_JUMPIF:
                    $jumpIfOutcome = $this->executeJumpIfDispatch($frame, $op);
                    if ($jumpIfOutcome instanceof Frame) {
                        $frame = $jumpIfOutcome;
                        goto restart;
                    }
                    if (is_int($jumpIfOutcome)) {
                        return $jumpIfOutcome;
                    }
                    break;
                case OpCode::TYPE_CASE:
                    $caseOutcome = $this->executeCaseDispatch($frame, $op);
                    if ($caseOutcome instanceof Frame) {
                        $frame = $caseOutcome;
                        goto restart;
                    }
                    if (is_int($caseOutcome)) {
                        return $caseOutcome;
                    }
                    break;
                case OpCode::TYPE_CONST_FETCH:
                    $constFetchOutcome = $this->executeConstFetchDispatch($frame, $op);
                    if ($constFetchOutcome instanceof Frame) {
                        $frame = $constFetchOutcome;
                        goto restart;
                    }
                    if (is_int($constFetchOutcome)) {
                        return $constFetchOutcome;
                    }
                    break;
                case OpCode::TYPE_STATICCALL_INIT:
                    $staticCallInitOutcome = $this->executeStaticCallInitDispatch($frame, $op);
                    if ($staticCallInitOutcome instanceof Frame) {
                        $frame = $staticCallInitOutcome;
                        goto restart;
                    }
                    if (is_int($staticCallInitOutcome)) {
                        return $staticCallInitOutcome;
                    }
                    break;
                case OpCode::TYPE_CLASS_CONST_FETCH:
                    $classConstFetchOutcome = $this->executeClassConstFetchDispatch($frame, $op);
                    if ($classConstFetchOutcome instanceof Frame) {
                        $frame = $classConstFetchOutcome;
                        goto restart;
                    }
                    if (is_int($classConstFetchOutcome)) {
                        return $classConstFetchOutcome;
                    }
                    break;
                case OpCode::TYPE_INSTANCEOF:
                    $instanceofOutcome = $this->executeInstanceofDispatch($frame, $op);
                    if ($instanceofOutcome instanceof Frame) {
                        $frame = $instanceofOutcome;
                        goto restart;
                    }
                    if (is_int($instanceofOutcome)) {
                        return $instanceofOutcome;
                    }
                    break;
                case OpCode::TYPE_IN:
                    $inOutcome = $this->executeInDispatch($frame, $op);
                    if ($inOutcome instanceof Frame) {
                        $frame = $inOutcome;
                        goto restart;
                    }
                    if (is_int($inOutcome)) {
                        return $inOutcome;
                    }
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_FETCH:
                    $staticPropFetchOutcome = $this->executeStaticPropertyFetchDispatch($frame, $op);
                    if ($staticPropFetchOutcome instanceof Frame) {
                        $frame = $staticPropFetchOutcome;
                        goto restart;
                    }
                    if (is_int($staticPropFetchOutcome)) {
                        return $staticPropFetchOutcome;
                    }
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_UNSET:
                    $staticPropUnsetOutcome = $this->executeStaticPropertyUnsetDispatch($frame, $op);
                    if ($staticPropUnsetOutcome instanceof Frame) {
                        $frame = $staticPropUnsetOutcome;
                        goto restart;
                    }
                    if (is_int($staticPropUnsetOutcome)) {
                        return $staticPropUnsetOutcome;
                    }
                    break;
                case OpCode::TYPE_UNSET:
                    $unsetOutcome = $this->executeUnsetDispatch($frame, $op);
                    if ($unsetOutcome instanceof Frame) {
                        $frame = $unsetOutcome;
                        goto restart;
                    }
                    if (is_int($unsetOutcome)) {
                        return $unsetOutcome;
                    }
                    break;
                case OpCode::TYPE_FROM_CALLABLE:
                case OpCode::TYPE_CLOSURE:
                    $fromCallableClosureOutcome = $this->executeFromCallableAndClosureDispatch($frame, $op);
                    if ($fromCallableClosureOutcome instanceof Frame) {
                        $frame = $fromCallableClosureOutcome;
                        goto restart;
                    }
                    if (is_int($fromCallableClosureOutcome)) {
                        return $fromCallableClosureOutcome;
                    }
                    break;
                case OpCode::TYPE_RETURN_VOID:
                case OpCode::TYPE_RETURN:
                    $returnDispatchOutcome = $this->executeReturnDispatch($frame, $op);
                    if ($returnDispatchOutcome instanceof Frame) {
                        $frame = $returnDispatchOutcome;
                        goto restart;
                    }
                    if (self::RETURN_NEXTFRAME === $returnDispatchOutcome) {
                        goto nextframe;
                    }

                    return $returnDispatchOutcome;
                case OpCode::TYPE_FUNCDEF:
                    $funcDefOutcome = $this->executeFuncDefAndGlobalConstDispatch($frame, $op);
                    if ($funcDefOutcome instanceof Frame) {
                        $frame = $funcDefOutcome;
                        goto restart;
                    }
                    if (is_int($funcDefOutcome)) {
                        return $funcDefOutcome;
                    }
                    break;
                case OpCode::TYPE_FUNCCALL_INIT:
                    $funcCallInitOutcome = $this->executeFuncCallInitDispatch($frame, $op);
                    if ($funcCallInitOutcome instanceof Frame) {
                        $frame = $funcCallInitOutcome;
                        goto restart;
                    }
                    if (is_int($funcCallInitOutcome)) {
                        return $funcCallInitOutcome;
                    }
                    break;
                case OpCode::TYPE_METHODCALL_INIT:
                    $methodCallInitOutcome = $this->executeMethodCallInitDispatch($frame, $op);
                    if ($methodCallInitOutcome instanceof Frame) {
                        $frame = $methodCallInitOutcome;
                        goto restart;
                    }
                    if (is_int($methodCallInitOutcome)) {
                        return $methodCallInitOutcome;
                    }
                    break;
                case OpCode::TYPE_ARG_SEND:
                    $argSendOutcome = $this->executeArgSendDispatch($frame, $op);
                    if ($argSendOutcome instanceof Frame) {
                        $frame = $argSendOutcome;
                        goto restart;
                    }
                    if (is_int($argSendOutcome)) {
                        return $argSendOutcome;
                    }
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    $funcCallExecOutcome = $this->executeFuncCallExecDispatch($frame, $op);
                    if ($funcCallExecOutcome instanceof Frame) {
                        $frame = $funcCallExecOutcome;
                        goto restart;
                    }
                    if (is_int($funcCallExecOutcome)) {
                        return $funcCallExecOutcome;
                    }
                    break;
                case OpCode::TYPE_ARG_RECV:
                    $argRecvOutcome = $this->executeArgRecvDispatch($frame, $op);
                    if ($argRecvOutcome instanceof Frame) {
                        $frame = $argRecvOutcome;
                        goto restart;
                    }
                    if (is_int($argRecvOutcome)) {
                        return $argRecvOutcome;
                    }
                    break;
                case OpCode::TYPE_DECLARE_INTERFACE:
                    $declareIfaceOutcome = $this->executeDeclareInterfaceDispatch($frame, $op);
                    if ($declareIfaceOutcome instanceof Frame) {
                        $frame = $declareIfaceOutcome;
                        goto restart;
                    }
                    if (is_int($declareIfaceOutcome)) {
                        return $declareIfaceOutcome;
                    }
                    break;
                case OpCode::TYPE_DECLARE_TRAIT:
                    $declareTraitOutcome = $this->executeDeclareTraitDispatch($frame, $op);
                    if ($declareTraitOutcome instanceof Frame) {
                        $frame = $declareTraitOutcome;
                        goto restart;
                    }
                    if (is_int($declareTraitOutcome)) {
                        return $declareTraitOutcome;
                    }
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL_CONST:
                    $globalConstOutcome = $this->executeFuncDefAndGlobalConstDispatch($frame, $op);
                    if ($globalConstOutcome instanceof Frame) {
                        $frame = $globalConstOutcome;
                        goto restart;
                    }
                    if (is_int($globalConstOutcome)) {
                        return $globalConstOutcome;
                    }
                    break;
                case OpCode::TYPE_DECLARE_ENUM:
                    $declareEnumOutcome = $this->executeDeclareEnumDispatch($frame, $op);
                    if ($declareEnumOutcome instanceof Frame) {
                        $frame = $declareEnumOutcome;
                        goto restart;
                    }
                    if (is_int($declareEnumOutcome)) {
                        return $declareEnumOutcome;
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $declareClassOutcome = $this->executeDeclareClassDispatch($frame, $op);
                    if ($declareClassOutcome instanceof Frame) {
                        $frame = $declareClassOutcome;
                        goto restart;
                    }
                    if (is_int($declareClassOutcome)) {
                        return $declareClassOutcome;
                    }
                    break;
                case OpCode::TYPE_NEW:
                    $newOutcome = $this->executeNewDispatch($frame, $op);
                    if ($newOutcome instanceof Frame) {
                        $frame = $newOutcome;
                        goto restart;
                    }
                    if (is_int($newOutcome)) {
                        return $newOutcome;
                    }
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                case OpCode::TYPE_PROPERTY_FETCH_WRITE:
                    $propFetchOutcome = $this->executePropertyFetchDispatch($frame, $op);
                    if ($propFetchOutcome instanceof Frame) {
                        $frame = $propFetchOutcome;
                        goto restart;
                    }
                    if (is_int($propFetchOutcome)) {
                        return $propFetchOutcome;
                    }
                    break;
                case OpCode::TYPE_INIT_ARRAY:
                case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                case OpCode::TYPE_ARRAY_SPREAD:
                    $arrayInitSpreadOutcome = $this->executeArrayInitSpreadDispatch($frame, $op);
                    if ($arrayInitSpreadOutcome instanceof Frame) {
                        $frame = $arrayInitSpreadOutcome;
                        goto restart;
                    }
                    if (is_int($arrayInitSpreadOutcome)) {
                        return $arrayInitSpreadOutcome;
                    }
                    break;
                case OpCode::TYPE_CLONE:
                    $cloneOutcome = $this->executeCloneDispatch($frame, $op);
                    if ($cloneOutcome instanceof Frame) {
                        $frame = $cloneOutcome;
                        goto restart;
                    }
                    if (is_int($cloneOutcome)) {
                        return $cloneOutcome;
                    }
                    break;
                case OpCode::TYPE_BOOLEAN_NOT:
                case OpCode::TYPE_EMPTY:
                case OpCode::TYPE_EMPTY_OBJECT_PROPERTY:
                case OpCode::TYPE_EMPTY_STATIC_PROPERTY:
                case OpCode::TYPE_EMPTY_DIMENSION:
                    $emptyBoolOutcome = $this->executeEmptyAndBooleanNotDispatch($frame, $op);
                    if ($emptyBoolOutcome instanceof Frame) {
                        $frame = $emptyBoolOutcome;
                        goto restart;
                    }
                    if (is_int($emptyBoolOutcome)) {
                        return $emptyBoolOutcome;
                    }
                    break;
                case OpCode::TYPE_ISSET:
                    $issetOutcome = $this->executeIssetDispatch($frame, $op);
                    if ($issetOutcome instanceof Frame) {
                        $frame = $issetOutcome;
                        goto restart;
                    }
                    if (is_int($issetOutcome)) {
                        return $issetOutcome;
                    }
                    break;
                case OpCode::TYPE_SCRIPT_MAGIC:
                    $scriptMagicOutcome = $this->executeScriptMagicAndTickDispatch($frame, $op);
                    if ($scriptMagicOutcome instanceof Frame) {
                        $frame = $scriptMagicOutcome;
                        goto restart;
                    }
                    if (is_int($scriptMagicOutcome)) {
                        return $scriptMagicOutcome;
                    }
                    break;
                case OpCode::TYPE_INCLUDE:
                    $includeOutcome = $this->executeIncludeDispatch($frame, $op);
                    if ($includeOutcome instanceof Frame) {
                        $frame = $includeOutcome;
                        goto restart;
                    }
                    if (is_int($includeOutcome)) {
                        return $includeOutcome;
                    }
                    break;
                case OpCode::TYPE_YIELD:
                case OpCode::TYPE_YIELD_FROM:
                    $yieldOutcome = $this->executeYieldAndYieldFromDispatch($frame, $op);
                    if ($yieldOutcome instanceof Frame) {
                        $frame = $yieldOutcome;
                        goto restart;
                    }
                    if (is_int($yieldOutcome)) {
                        return $yieldOutcome;
                    }
                    break;
                case OpCode::TYPE_ITER_RESET:
                    $iterResetOutcome = $this->executeIterResetDispatch($frame, $op);
                    if ($iterResetOutcome instanceof Frame) {
                        $frame = $iterResetOutcome;
                        goto restart;
                    }
                    if (is_int($iterResetOutcome)) {
                        return $iterResetOutcome;
                    }
                    break;
                case OpCode::TYPE_ITER_VALID:
                    $iterValidOutcome = $this->executeIterValidDispatch($frame, $op);
                    if ($iterValidOutcome instanceof Frame) {
                        $frame = $iterValidOutcome;
                        goto restart;
                    }
                    if (is_int($iterValidOutcome)) {
                        return $iterValidOutcome;
                    }
                    break;
                case OpCode::TYPE_ITER_KEY:
                    $iterKeyOutcome = $this->executeIterKeyDispatch($frame, $op);
                    if ($iterKeyOutcome instanceof Frame) {
                        $frame = $iterKeyOutcome;
                        goto restart;
                    }
                    if (is_int($iterKeyOutcome)) {
                        return $iterKeyOutcome;
                    }
                    break;
                case OpCode::TYPE_ITER_VALUE:
                    $iterValueOutcome = $this->executeIterValueDispatch($frame, $op);
                    if ($iterValueOutcome instanceof Frame) {
                        $frame = $iterValueOutcome;
                        goto restart;
                    }
                    if (is_int($iterValueOutcome)) {
                        return $iterValueOutcome;
                    }
                    break;
                case OpCode::TYPE_TRY:
                case OpCode::TYPE_CATCH:
                case OpCode::TYPE_FINALLY:
                case OpCode::TYPE_THROW:
                case OpCode::TYPE_RETHROW:
                    $tryCatchThrowOutcome = $this->executeTryCatchThrowDispatch($frame, $op);
                    if ($tryCatchThrowOutcome instanceof Frame) {
                        $frame = $tryCatchThrowOutcome;
                        goto restart;
                    }
                    if (is_int($tryCatchThrowOutcome)) {
                        return $tryCatchThrowOutcome;
                    }
                    break;
                case OpCode::TYPE_TICK_SCOPE_ENTER:
                case OpCode::TYPE_TICK_SCOPE_SET:
                case OpCode::TYPE_TICK_SCOPE_LEAVE:
                case OpCode::TYPE_TICKS:
                    $tickOutcome = $this->executeScriptMagicAndTickDispatch($frame, $op);
                    if ($tickOutcome instanceof Frame) {
                        $frame = $tickOutcome;
                        goto restart;
                    }
                    if (is_int($tickOutcome)) {
                        return $tickOutcome;
                    }
                    break;
                default:
                    throw new \LogicException("VM OpCode Not Implemented: " . opcode_type_name($op->type));
                }
            } catch (TypedPropertyReadSignal $signal) {
                $catchFrame = $this->dispatchEngineThrow($frame, $signal->errorObject);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    goto restart;
                }

                return self::FAILURE;
            } catch (VM\PropertyHookRefWriteSignal $signal) {
                $frame = $signal->catchFrame;
                goto restart;
            } catch (VM\PropertyHookFiberSuspendSignal $signal) {
                $fiber = $this->context->currentFiber;
                if (null !== $fiber) {
                    $fiber->propertyHookSuspendFrame = $fiber->frame;
                    $fiber->frame = $signal->resumeFrame;
                }
                // pos is pre-incremented at loop head; re-run the property fetch on resume (#9862).
                if ($signal->resumeFrame->pos > 0) {
                    --$signal->resumeFrame->pos;
                }

                return self::FIBER_SUSPEND;
            } catch (VM\ArrayAccessOffsetSignal $signal) {
                $frame = $signal->catchFrame;
                goto restart;
            } catch (VM\DestructorThrowCatchSignal $signal) {
                if ($this->context->isolatedDestructorInvoke) {
                    throw $signal;
                }
                $frame = $signal->catchFrame;
                goto restart;
            } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                if (
                    $this->context->deferBuiltinCallbackCatchToOuterRunFrames
                    || null !== $this->context->deferCatchBelowTryHandlerDepth
                ) {
                    throw $redirect;
                }
                $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                goto restart;
            } catch (VM\CloneMagicCatchRedirect $redirect) {
                // Isolated __clone stack: abort nested runFrames; clone opcode resumes outer catch (#23527).
                $this->context->cloneMagicExternalCatchFrame = $redirect->catchFrame;

                return self::FAILURE;
            }
            if ($this->shouldAbortPropertyHookInvocation($frame)) {
                return self::FAILURE;
            }
            if ($frame->generatorYield) {
                $frame->generatorYield = false;

                return self::GENERATOR_YIELD;
            }
            if ($frame->fiberSuspend) {
                $frame->fiberSuspend = false;
                $frame->call = null;
                $this->clearOutgoingCallState($frame);
                $this->restorePendingOutboundCallAfterInlineNew($frame);

                return self::FIBER_SUSPEND;
            }
        }
        if ($frame->ephemeral) {
            $this->context->scriptStack->pop();
            if (null !== $frame->parent) {
                $frame = $this->resumeEphemeralCallerFrame($frame);
                goto restart;
            }
            $this->releaseFrameObjectRefs($frame);
            goto nextframe;
        }
        if ([] !== $this->context->deferredTraitUses) {
            $this->finalizeDeferredTraitUses();
        }
        if ([] !== $this->context->deferredClassConstants) {
            $this->finalizeAllDeferredClassConstants();
        }
        if ([] !== $this->context->deferredParentInheritance) {
            try {
                $this->finalizeDeferredParentInheritance($frame);
            } catch (\CompileError $deferredCompileError) {
                $this->raiseClassDeclareCompileFatal($deferredCompileError, $frame);
            } catch (\Error $deferredParentError) {
                $catchFrame = $this->dispatchVmError($deferredParentError->getMessage(), $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    goto restart;
                }

                return self::FAIL;
            }
        }

        return self::SUCCESS;
    }
}
