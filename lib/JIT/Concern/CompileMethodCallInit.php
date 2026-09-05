<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;

/**
 * Method / static call-init opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_STATICCALL_INIT} and
 * {@code TYPE_METHODCALL_INIT}. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_INIT_METHOD_CALL / ZEND_INIT_STATIC_METHOD_CALL /
 * ZEND_INIT_DYNAMIC_CALL), Zend/zend_execute.c — move-only Concern extract; no new C ABI.
 */
trait CompileMethodCallInit
{
    private function compileStaticCallInitOp(Block $block, OpCode $op): void
    {
        // Nested `new T(self::make(), …)` / AppFactory::create: STATICCALL_INIT
        // must save the pending TYPE_NEW construct like FUNCCALL_INIT (#36382).
        $this->saveJitPendingOutboundCall();
        $this->initJitStaticCall($block, $op->arg1, $op->arg2, $op->staticCallParentScope);
    }

    private function compileMethodCallInitOp(Block $block, OpCode $op): void
    {
        // Nested `$obj->m()` while a TYPE_NEW construct is pending — same save
        // as FUNCCALL_INIT / STATICCALL_INIT (#36382 / #27242).
        $this->saveJitPendingOutboundCall();
        $receiverOp = $block->getOperand($op->arg1);
        $nameOp = $block->getOperand($op->arg2);
        // `$obj->$m()` — name may be a Temporary; VM uses scope[arg2]->toString()
        // (#34084). Fold compile-time string like FUNCCALL_INIT (#1997); #8407 was
        // variable *receiver*, not variable *name*.
        $nameSlot = $op->arg2;
        if (!$nameOp instanceof Operand\Literal && isset($block->constants[$nameSlot])) {
            $nameOp = new Operand\Literal($block->constants[$nameSlot]->toString());
        }
        $methodName = null;
        $nameVar = null;
        if ($nameOp instanceof Operand\Literal) {
            $methodName = is_string($nameOp->value) ? $nameOp->value : (string) $nameOp->value;
        } else {
            $nameVar = $this->context->getVariableFromOp($nameOp);
            $slot = $block->slotForOperand($nameOp);
            if (null !== $slot) {
                $this->foldCompileTimeStringFromSlot($block, $slot, $nameVar);
            }
            if (null !== $nameVar->compileTimeString) {
                $methodName = $nameVar->compileTimeString;
            }
        }
        if (null === $methodName || '' === $methodName) {
            // Runtime method name: `$this->$methodName()` after concat / HT fetch
            // (Parsedown blockContinue / element handler — #36380). Peer of
            // Class::$m() via RuntimeVariableStaticMethodCall (#34937).
            if (null === $nameVar) {
                $nameVar = $this->context->getVariableFromOp($nameOp);
            }
            $receiverVar = $this->context->getVariableFromOp($receiverOp);
            $declaredLc = strtolower(ltrim((string) (
                $receiverVar->classUserType
                ?? $this->typedPropertyClassConstraintUserType($receiverVar)
                ?? $receiverOp->type?->userType
                ?? $this->context->scope->className
                ?? ''
            ), '\\'));
            $candidates = $this->buildRuntimeInstanceMethodCandidatesByMethodName($declaredLc);
            if ([] === $candidates) {
                throw new \LogicException(
                    'Instance method call name must be a compile-time string or a typed receiver with known methods (dynamic $obj->$name(); #34084 / #36380)'
                );
            }
            $this->context->scope->toCall = new \PHPCompiler\JIT\Call\RuntimeVariableStaticMethodCall(
                $nameVar,
                $candidates
            );
            $this->context->scope->args = [$receiverVar];
            $this->context->scope->callArgsIncludeReceiver = true;
            $this->context->scope->argOperands = [$receiverOp];

            return;
        }
        $this->initJitMethodCall($block, $receiverOp, $methodName, $op->objectCallInvoke);
        // initJitMethodCall seeds args=[receiver] but not argOperands. ARG_SEND only
        // appends user-arg operands — without this prefix, promoteCompileTimeStringOnCallArgs
        // pairs each arg with the *next* operand and shifts compileTimeString (#35234).
        if (
            1 === \count($this->context->scope->args)
            && ($this->context->scope->args[0] ?? null) instanceof Variable
        ) {
            $this->context->scope->argOperands = [$receiverOp];
        }
    }
}
