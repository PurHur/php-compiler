<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\JIT\Variable;

/**
 * FUNCCALL_INIT opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_FUNCCALL_INIT}. Wrapped in
 * {@code switch (true)} so original case-level {@code break} semantics are
 * preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_INIT_FCALL / ZEND_INIT_FCALL_BY_NAME /
 * ZEND_INIT_NS_FCALL_BY_NAME / ZEND_INIT_DYNAMIC_CALL), Zend/zend_execute.c —
 * move-only Concern extract; no new C ABI.
 */
trait CompileFuncCallInit
{
    private function compileFuncCallInitOp(
        Block $block,
        OpCode $op
    ): void {
        switch (true) {
            case true:
                    // Nested inline arg call must not clobber outer pending callee (#15217 VM / #27242 AOT).
                    if ($block->isMainScript() && null === $this->context->scope->toCall) {
                        // Literal `echo` between consecutive top-level calls left stale restore
                        // stack and intermittent SIGSEGV on the next INIT (#23472). Only discard
                        // leftovers when there is no live outer callee — wiping toCall here
                        // dropped json_encode() when JSON_* ConstFetch hoisted INIT before a
                        // nested mb_str_split() INIT (#27242).
                        if ([] !== $this->context->scope->pendingOutboundCallRestore) {
                            $this->context->scope->pendingOutboundCallRestore = [];
                        }
                    }
                    $this->saveJitPendingOutboundCall();
                    $nameOp = $block->getOperand($op->arg1);
                    if ($nameOp instanceof Operand\Literal) {
                        $lcname = strtolower($nameOp->value);
                        if (
                            $op->funcCallDynamic
                            && \PHPCompiler\VM\VariableFunctionCall::isForbiddenWhenDynamic($lcname)
                        ) {
                            \PHPCompiler\JIT\ErrorBridge::emitError(
                                $this->context,
                                \PHPCompiler\VM\VariableFunctionCall::forbiddenWhenDynamicMessage($lcname)
                            );
                            $this->context->builder->call($this->context->lookupFunction('abort'));
                            $this->context->scope->toCall = null;
                            $this->context->scope->args = [];
                            $this->context->scope->callArgsIncludeReceiver = false;
                            $this->context->scope->argOperands = [];
                            break;
                        }
                        $this->context->scope->generatorResumeCallee = \PHPCompiler\JIT\GeneratorHelper::creatorResumeName(
                            $this->context,
                            $lcname
                        );
                        if (str_contains($nameOp->value, '::')) {
                            [$staticClass, $staticMethod] = explode('::', (string) $nameOp->value, 2);
                            $nonStaticMsg = $this->nonStaticClassMethodCallableMessage(
                                strtolower($staticClass),
                                strtolower($staticMethod),
                                $staticClass,
                                $staticMethod
                            );
                            if (null !== $nonStaticMsg) {
                                $this->context->scope->toCall = new \PHPCompiler\JIT\Call\EmitCatchableError($nonStaticMsg);
                                $this->context->scope->args = [];
                                $this->context->scope->callArgsIncludeReceiver = false;
                                $this->context->scope->argOperands = [];
                                break;
                            }
                        }
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy($lcname);
                    } else {
                        $nameVar = $this->context->getVariableFromOp($nameOp);
                        $nameSlot = $block->slotForOperand($nameOp);
                        // Fold ['Class','method']() before closure dispatch: once a closure body
                        // registers candidates, resolveIndirectCall treats any TYPE_VALUE callee as
                        // RuntimeIndirectClosureCall and aborts on array callables (#32299 / #33800).
                        if (null !== $nameSlot && $this->tryInitStaticArrayCallableDirect($block, $nameSlot)) {
                            $this->context->scope->argOperands = [];
                            break;
                        }
                        if (\PHPCompiler\JIT\BoundMethodCallableHelper::isBoundMethodArrayCallee($nameOp, $nameVar)) {
                            if ($this->tryInitBoundMethodFccDirect($block, $nameSlot)) {
                                $this->context->scope->argOperands = [];
                                break;
                            }
                        }
                        $closureCall = \PHPCompiler\JIT\ClosureHelper::resolveCall($this->context, $nameVar);
                        if (null === $closureCall) {
                            if (null !== $nameSlot) {
                                $closureCall = $this->resolveFccClosureCallForCalleeSlot($block, $nameSlot);
                                if (null !== $closureCall) {
                                    $nameVar->closureCall = $closureCall;
                                }
                            }
                        }
                        if (null !== $closureCall) {
                            // Method-returned closures are recorded as Native `Class::{closure}`
                            // by precompileClosuresBeforeQueue (before ClosureWithBinding is
                            // applied). Invoke must reload $this from the Closure heap (#35456).
                            if ($closureCall instanceof \PHPCompiler\JIT\Call\Native
                                && self::isClosureNativeInvokeName($closureCall->name)
                                && (Variable::TYPE_OBJECT === $nameVar->type
                                    || Variable::TYPE_VALUE === $nameVar->type)
                            ) {
                                $candidates = array_merge(
                                    \PHPCompiler\JIT\ClosureHelper::closureCandidates($this->context),
                                    $this->context->fccCallableProxies
                                );
                                if ([] !== $candidates) {
                                    $closureCall = new \PHPCompiler\JIT\Call\RuntimeIndirectClosureCall(
                                        $nameVar,
                                        $candidates,
                                        $this->context->type->object->lookup('Closure')
                                    );
                                    $nameVar->closureCall = $closureCall;
                                }
                            } elseif ($closureCall instanceof \PHPCompiler\JIT\Call\ClosureWithBinding
                                && (Variable::TYPE_OBJECT === $nameVar->type
                                    || Variable::TYPE_VALUE === $nameVar->type)
                            ) {
                                $closureCall = $closureCall->withClosureObject($nameVar);
                                $nameVar->closureCall = $closureCall;
                            }
                            $this->context->scope->toCall = $closureCall;
                            $this->context->scope->args = [];
                            $this->context->scope->callArgsIncludeReceiver = false;
                            $this->context->scope->argOperands = [];
                            break;
                        }
                        if (null !== $nameOp->type && Type::TYPE_OBJECT === $nameOp->type->type) {
                            $this->initJitMethodCall($block, $nameOp, '__invoke', true);
                            break;
                        }
                        if (null !== $nameSlot) {
                            $this->foldCompileTimeStringFromSlot($block, $nameSlot, $nameVar);
                        }
                        // foreach (['a','b'] as $fn): fold may pin compileTimeString to the first
                        // array literal, so every $fn() becomes a() (#35075). Multi-value foreach
                        // sources must stay dynamic.
                        $foreachCalleeHints = null !== $nameSlot
                            ? \PHPCompiler\JIT\VariableFunctionCallHelper::foreachArrayLiteralCalleeHints($block, $nameSlot)
                            : [];
                        if (\count($foreachCalleeHints) > 1) {
                            $nameVar->compileTimeString = null;
                        }
                        if (null === $nameVar->compileTimeString) {
                            if ($this->shouldUseSelfHostJitStubs()) {
                                $this->context->scope->toCall = null;
                                $this->context->scope->args = [];
                                $this->context->scope->callArgsIncludeReceiver = false;
                                $this->context->scope->argOperands = [];
                                break;
                            }
                            $hints = array_values(array_unique(array_merge(
                                \PHPCompiler\JIT\VariableFunctionCallHelper::hintedCalleeNames($block, $nameSlot),
                                $foreachCalleeHints,
                                \PHPCompiler\JIT\VariableFunctionCallHelper::coalesceBranchLiteralHints($block),
                                \PHPCompiler\JIT\VariableFunctionCallHelper::funDefNamesInCompilationUnit($block)
                            )));
                            $this->context->scope->toCall = new \PHPCompiler\JIT\Call\RuntimeVariableFunction($nameVar, $hints);
                        } else {
                            $lcname = strtolower($nameVar->compileTimeString);
                            if (
                                $op->funcCallDynamic
                                && \PHPCompiler\VM\VariableFunctionCall::isForbiddenWhenDynamic($lcname)
                            ) {
                                \PHPCompiler\JIT\ErrorBridge::emitError(
                                    $this->context,
                                    \PHPCompiler\VM\VariableFunctionCall::forbiddenWhenDynamicMessage($lcname)
                                );
                                $this->context->builder->call($this->context->lookupFunction('abort'));
                                $this->context->scope->toCall = null;
                                $this->context->scope->args = [];
                                $this->context->scope->callArgsIncludeReceiver = false;
                                $this->context->scope->argOperands = [];
                                break;
                            }
                            if (!$this->context->functionIsRegistered($lcname)) {
                                if (str_contains($nameVar->compileTimeString, '::')) {
                                    [$staticClass, $staticMethod] = explode('::', $nameVar->compileTimeString, 2);
                                    if ($this->tryResolveSelfHostSuperglobalsStaticCall($staticClass, $staticMethod)) {
                                        break;
                                    }
                                    if ($this->tryResolveProgressStaticCall($staticClass, $staticMethod)) {
                                        break;
                                    }
                                    // Zend zend_execute_API.c — same wording as instance miss (#27921).
                                    throw new \LogicException("Call to undefined method {$nameVar->compileTimeString}()");
                                }
                                // Preserve source spelling like Zend (#26690, zend_execute_API.c).
                                throw new \LogicException(
                                    'Call to undefined function '.$nameVar->compileTimeString.'()'
                                );
                            }
                            if (str_contains($nameVar->compileTimeString, '::')) {
                                [$staticClass, $staticMethod] = explode('::', $nameVar->compileTimeString, 2);
                                $nonStaticMsg = $this->nonStaticClassMethodCallableMessage(
                                    strtolower($staticClass),
                                    strtolower($staticMethod),
                                    $staticClass,
                                    $staticMethod
                                );
                                if (null !== $nonStaticMsg) {
                                    // $fn() / ['C','m']() — catchable Error, not self:: bind (#32299 / #31968).
                                    $this->context->scope->toCall = new \PHPCompiler\JIT\Call\EmitCatchableError($nonStaticMsg);
                                    $this->context->scope->args = [];
                                    $this->context->scope->callArgsIncludeReceiver = false;
                                    $this->context->scope->argOperands = [];
                                    break;
                                }
                            }
                            $this->context->scope->toCall = $this->context->resolveFunctionProxy($lcname);
                        }
                    }
                    $this->context->scope->args = [];
                    $this->context->scope->callArgsIncludeReceiver = false;
                    $this->context->scope->argOperands = [];
                    break;
        }
    }
}
