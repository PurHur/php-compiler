<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;

/**
 * ARG_RECV opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_ARG_RECV}.
 * Wrapped in {@code switch (true)} so original case-level {@code break}
 * semantics are preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_RECV / ZEND_RECV_INIT / ZEND_RECV_VARIADIC),
 * Zend/zend_execute.c (zend_verify_arg_type / recv) — move-only Concern extract;
 * no new C ABI.
 */
trait CompileArgRecv
{
    /**
     * @param Variable ...$args prologue-bound formals (optional leading $this)
     */
    private function compileArgRecvOp(
        Block $block,
        OpCode $op,
        int $thisParamOffset,
        Variable ...$args
    ): void {
        switch (true) {
            case true:
                    // Resume fn has no PHP argv — formals live in heap frame filled at
                    // generator create (emitCreateFromCall) (#35142).
                    if ($this->context->compilingGeneratorResume) {
                        break;
                    }
                    $recvSlot = $op->arg2 + $thisParamOffset;
                    $isVariadicSlot = null !== $block->variadicParamIndex
                        && $block->variadicParamIndex === (int) $op->arg2;
                    if ($isVariadicSlot) {
                        $packed = isset($args[$recvSlot])
                            ? $args[$recvSlot]
                            : \PHPCompiler\JIT\HashTableHelper::emptyVariable($this->context);
                        // Keep TYPE_HASHTABLE — do not box here. Boxing made foreach over
                        // `...$args` / `&...$args` emit parentless `__value__readObject` IR
                        // (#34684) and broke by-ref element write-back (#27407). Array builtins
                        // that need a value-box coerce at the call site (ArraySumLlvm / #24167).
                        $recvOp = $block->getOperand($op->arg1);
                        // Prologue already assignOperand'd the packed HT onto Param.result. A
                        // second assignOperand (same slot or Temporary) free()s that HT
                        // (delref) before re-storing — dangling pack; $v[0]/implode/foreach
                        // SEGV (e08_spread #24226, k09 #24167). Same skip as typed `array`
                        // formals below (#36386 / #36397).
                        if (
                            Variable::TYPE_HASHTABLE === $packed->type
                            && $this->context->hasVariableOp($recvOp)
                            && Variable::TYPE_HASHTABLE === $this->context->getVariableFromOp($recvOp)->type
                        ) {
                            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                $this->context,
                                $recvOp,
                                $this->context->getVariableFromOp($recvOp)
                            );
                            if (isset($block->paramByRef[(int) $op->arg2])) {
                                $this->context->getVariableFromOp($recvOp)->borrowedHashtable = true;
                            }
                            break;
                        }
                        // Same-slot Temporary distinct from Param.result — alias the prologue
                        // HT instead of assignOperand (which would delref the live pack).
                        if (
                            !$this->context->hasVariableOp($recvOp)
                            && null !== $block->func
                            && isset($block->func->params[(int) $op->arg2])
                        ) {
                            $paramResult = $block->func->params[(int) $op->arg2]->result;
                            if (
                                $this->context->hasVariableOp($paramResult)
                                && Variable::TYPE_HASHTABLE === $this->context->getVariableFromOp($paramResult)->type
                            ) {
                                $bound = $this->context->getVariableFromOp($paramResult);
                                $this->context->setVariableOp($recvOp, $bound);
                                \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                    $this->context,
                                    $recvOp,
                                    $bound
                                );
                                if (isset($block->paramByRef[(int) $op->arg2])) {
                                    $bound->borrowedHashtable = true;
                                }
                                break;
                            }
                        }
                        $this->assignOperand($recvOp, $packed, true);
                        // `&...$args`: dim writes must hit the same HT syncByRefVariadicCallers
                        // reads — FETCH_DIM_W COW would leave the pack stale (#34790 / #34508).
                        if (
                            isset($block->paramByRef[(int) $op->arg2])
                            && $this->context->hasVariableOp($recvOp)
                        ) {
                            $this->context->getVariableFromOp($recvOp)->borrowedHashtable = true;
                        }
                        break;
                    }
                    if (!isset($args[$recvSlot])) {
                        throw new \LogicException('Missing required argument ' . $op->arg2);
                    }
                    if (isset($block->paramByRef[(int) $op->arg2])) {
                        $recvOp = $block->getOperand($op->arg1);
                        // getOperand may return a same-slot Temporary distinct from the CFG
                        // Param.result Variable already bound in the prologue (#24162).
                        if (!$this->context->hasVariableOp($recvOp)) {
                            $this->context->getVariableFromOp($recvOp);
                        }
                        $this->bindJitParamByReference(
                            $block,
                            $recvOp,
                            $args[$recvSlot]
                        );
                    } else {
                        // Prologue already bind+separate string formals via the
                        // LLVM function signature (AOT) or prepareNestedJitCalleeParamArgument
                        // (NestedJIT). Re-assigning the raw LLVM formal here empties heap
                        // __string__* content (length ok, bytes gone / UAF) — #24137, #24723.
                        // Skip ARG_RECV overwrite when the recv op is already bound — but still
                        // markAssigned so undefined-variable guards stay quiet (#31101 MiniWebApp
                        // `$route` warnings on stderr after string formal prologue bind).
                        $recvOp = $block->getOperand($op->arg1);
                        if (
                            Variable::TYPE_STRING === $args[$recvSlot]->type
                            && $this->context->hasVariableOp($recvOp)
                        ) {
                            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                $this->context,
                                $recvOp,
                                $this->context->getVariableFromOp($recvOp)
                            );
                            break;
                        }
                        // Prologue already assignOperand'd typed `array` formals onto a
                        // KIND_VARIABLE slot. A second assignOperand free()s that HT
                        // (delref) before re-storing — with caller rc=1 that frees the
                        // table under the callee and the caller's value-box (#36386).
                        // Same shape as the string skip above (#24137).
                        if (
                            Variable::TYPE_HASHTABLE === $args[$recvSlot]->type
                            && $this->context->hasVariableOp($recvOp)
                        ) {
                            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                $this->context,
                                $recvOp,
                                $this->context->getVariableFromOp($recvOp)
                            );
                            break;
                        }
                        // Typed `object` / class formals: prologue already stored the
                        // `__object__*` into the local. A second assignOperand runs
                        // obj_mirror delref on the live object then re-stores — that
                        // drops the sole caller ref so `take($this)` leaves typed props
                        // uninitialized (#36382 AppFactory Runner($this) / Holder($this)).
                        // Peer of string (#24137) and hashtable (#36386) ARG_RECV skips.
                        if (
                            Variable::TYPE_OBJECT === $args[$recvSlot]->type
                            && $this->context->hasVariableOp($recvOp)
                        ) {
                            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                $this->context,
                                $recvOp,
                                $this->context->getVariableFromOp($recvOp)
                            );
                            break;
                        }
                        if ($this->storeJitCalleeValueStructFormal(
                            $recvOp,
                            $this->prepareNestedJitCalleeParamArgument($args[$recvSlot])
                        )) {
                            break;
                        }
                        $this->assignOperand(
                            $recvOp,
                            $this->prepareNestedJitCalleeParamArgument($args[$recvSlot])
                        );
                    }
                    break;
        }
    }
}
