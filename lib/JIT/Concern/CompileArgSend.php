<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;

/**
 * ARG_SEND opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_ARG_SEND}. Wrapped in
 * {@code switch (true)} so original case-level {@code break} semantics are
 * preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_SEND_VAL / ZEND_SEND_VAR / ZEND_SEND_REF /
 * ZEND_SEND_VAL_EX / ZEND_SEND_VAR_EX / ZEND_SEND_VAR_NO_REF /
 * ZEND_SEND_USER / ZEND_SEND_ARRAY / ZEND_SEND_UNPACK), Zend/zend_execute.c —
 * move-only Concern extract; no new C ABI.
 */
trait CompileArgSend
{
    private function compileArgSendOp(
        Block $block,
        OpCode $op
    ): void {
        switch (true) {
            case true:
                    if ($this->context->inlineIncludeDepth > 0) {
                        $sendSlotPeek = (int) $op->arg1;
                        $sendOpPeek = $this->context->coalesceMergeSlotOperands[$sendSlotPeek]
                            ?? $block->getOperand($sendSlotPeek);
                        $sendName = null !== $sendOpPeek ? \PHPCompiler\JIT\OperandName::resolve($sendOpPeek) : null;
                        // Refresh inherited locals before calls that read them after ?? (#866).
                        // Do not refresh when sending post-include locals ($scriptBase): full-frame
                        // restore was corrupting those string slots (MiniWebApp munmap, #20507).
                        if (\PHPCompiler\JIT\IncludeBindingEmitHelper::refreshFrameDeclaresName($this->context, $sendName)) {
                            \PHPCompiler\JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                        }
                    }
                    $sendSlot = (int) $op->arg1;
                    $coalesceMergeOperand = $this->context->coalesceMergeSlotOperands[$sendSlot] ?? null;
                    $sendOperand = $coalesceMergeOperand ?? $block->getOperand($sendSlot);
                    if (
                        null !== $sendOperand
                        && !$this->context->hasVariableOp($sendOperand)
                    ) {
                        $this->context->aliasVariableOpFromSlot($block, $sendOperand);
                    }
                    if (null !== $coalesceMergeOperand) {
                        $sendValue = $this->materializeCoalesceMergeSlotArgSend($block, $sendOperand);
                    } elseif (null === $sendOperand && isset($block->constants[$sendSlot])) {
                        // Nested appendChild(createElement('r')) before importNode leaves the
                        // createElement name ARG_SEND with a string constant but no Block operand
                        // (#34302 / re-#24571). Rematerialize like bool/int/null (#27623).
                        $sendValue = \PHPCompiler\JIT\VmConstantJit::toVariable(
                            $this->context,
                            $block->constants[$sendSlot]
                        );
                    } elseif (null === $sendOperand) {
                        throw new \LogicException(
                            'ARG_SEND slot '.$sendSlot.' has neither operand nor constant'
                        );
                    } else {
                        $sendLocalName = \PHPCompiler\JIT\OperandName::resolve($sendOperand);
                        $outgoingArgIndex = \count($this->context->scope->args);
                        $skipUndefGuardForByRefSend = $this->isOutgoingByRefArgIndex(
                            $this->context->scope->toCall,
                            $outgoingArgIndex
                        );
                        // Prefer a live named binding over the {main} script-global heap box.
                        // CONCAT stores string bytes in a local alloca while ARG_SEND/strlen
                        // used to read the empty module box (#36366). Float binary results
                        // from property loads similarly land in a local `__value__` alloca
                        // while sqrt()/math builtins read the empty script-global (#36386).
                        $sendValue = null;
                        if (null !== $sendLocalName && '' !== $sendLocalName) {
                            $boundName = $this->context->resolveRefAliasName($sendLocalName);
                            if (isset($this->context->namedVariableBindings[$boundName])) {
                                $bound = $this->context->namedVariableBindings[$boundName];
                                if (
                                    Variable::KIND_VARIABLE === $bound->kind
                                    && (
                                        Variable::TYPE_STRING === $bound->type
                                        || Variable::TYPE_VALUE === $bound->type
                                        || Variable::TYPE_NATIVE_DOUBLE === $bound->type
                                        || Variable::TYPE_NATIVE_LONG === $bound->type
                                        || Variable::TYPE_NATIVE_BOOL === $bound->type
                                    )
                                ) {
                                    $sendValue = $bound;
                                }
                            }
                        }
                        // {main} script globals: scope slots can retain stale NATIVE_LONG after
                        // the heap box was updated (echo path #23842; substr_compare $length #4297).
                        if (null === $sendValue) {
                            $sendValue = $this->resolveScriptGlobalForRuntimeRead(
                                $sendOperand,
                                $block,
                                null,
                                $skipUndefGuardForByRefSend
                            );
                        }
                        if (null === $sendValue) {
                            $sendValue = $this->context->getVariableFromOp($sendOperand);
                            if (null !== $sendLocalName && '' !== $sendLocalName) {
                                $boundName = $this->context->resolveRefAliasName($sendLocalName);
                                if (isset($this->context->namedVariableBindings[$boundName])) {
                                    $sendValue = $this->context->namedVariableBindings[$boundName];
                                }
                            }
                        }
                        if (
                            isset($block->constants[$sendSlot])
                            && \PHPCompiler\VM\Variable::TYPE_FLOAT === $block->constants[$sendSlot]->type
                        ) {
                            // Always rematerialize float ConstFetch at ARG_SEND — AOT Instruction
                            // loads are single-use; 2nd INF/NAN (or float after another float)
                            // otherwise arrives as a consumed SSA value (#27021).
                            $sendValue = \PHPCompiler\JIT\VmConstantJit::toVariable(
                                $this->context,
                                $block->constants[$sendSlot]
                            );
                        } elseif (
                            isset($block->constants[$sendSlot])
                            && (
                                \PHPCompiler\VM\Variable::TYPE_BOOLEAN === $block->constants[$sendSlot]->type
                                || \PHPCompiler\VM\Variable::TYPE_INTEGER === $block->constants[$sendSlot]->type
                                || \PHPCompiler\VM\Variable::TYPE_NULL === $block->constants[$sendSlot]->type
                            )
                            && (
                                null === $sendValue->compileTimeLong
                                || \PHPCompiler\VM\Variable::TYPE_NULL === $block->constants[$sendSlot]->type
                            )
                            && null === $sendLocalName
                            && !(
                                Variable::TYPE_VALUE === $sendValue->type
                                && Variable::KIND_VARIABLE === $sendValue->kind
                            )
                        ) {
                            // Bool/int/null literal Temporary often lands as TYPE_VALUE without
                            // compileTimeLong / isNullConstant; rematerialize so builtins can fold
                            // (json_decode assoc=null + JSON_THROW_ON_ERROR, #27623 / #23427).
                            // Named locals ($len = null) must stay value boxes for nullable length
                            // (substr_compare $length, #4297).
                            $sendValue = \PHPCompiler\JIT\VmConstantJit::toVariable(
                                $this->context,
                                $block->constants[$sendSlot]
                            );
                        } elseif (
                            Variable::TYPE_VALUE === $sendValue->type
                            && Variable::KIND_VARIABLE === $sendValue->kind
                        ) {
                            \PHPCompiler\JIT\JitValueBox::publishAfterWrite(
                                $this->context,
                                \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $sendValue)
                            );
                        }
                    }
                    if (null !== $op->arg3) {
                        $this->context->scope->args[] = ['unpack' => $sendValue];
                        $this->context->scope->argOperands[] = $block->getOperand($op->arg1);
                    } elseif (null !== $op->arg2 && isset($block->constants[$op->arg2])) {
                        $this->context->scope->args[] = [
                            'named' => $block->constants[$op->arg2]->toString(),
                            'value' => $sendValue,
                        ];
                        $this->context->scope->argOperands[] = $block->getOperand($op->arg1);
                    } else {
                        $this->context->scope->args[] = $sendValue;
                        $this->context->scope->argOperands[] = $block->getOperand($op->arg1);
                    }
                    break;
        }
    }
}
