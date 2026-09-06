<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;

/**
 * Global constant declare opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_DECLARE_GLOBAL_CONST}.
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_constants.c (zend_register_constant / ZEND_DECLARE_CONST),
 * Zend/zend_compile.c (zend_compile_const_decl) — move-only Concern extract; no new C ABI.
 */
trait CompileDeclareGlobalConst
{
    private function compileDeclareGlobalConstOp(Block $block, OpCode $op): void
    {
        $nameOp = $block->getOperand($op->arg1);
        assert($nameOp instanceof Operand\Literal);
        if (isset($block->constants[$op->arg2])) {
            $constValue = new \PHPCompiler\VM\Variable();
            $constValue->copyFrom($block->constants[$op->arg2]);
        } else {
            if ($this->shouldUseSelfHostJitStubs()) {
                return;
            }
            $vm = new VM($this->context->runtime->vmContext);
            // Seed user classes for `const C = new UserClass` — MODE_AOT skips
            // VM DECLARE_CLASS, same gap class-const materialization fixed (#19046 / #35196).
            $rootBlock = $this->context->jitFunctionRootBlock
                ?? $this->context->jitEnclosingBlock
                ?? $block;
            \PHPCompiler\VM\ClassConstMaterializer::seedReferencedClasses(
                $vm,
                $rootBlock,
                $block,
                $op->arg2
            );
            $constValue = \PHPCompiler\VM\ClassConstMaterializer::materializeGlobalConstSlot(
                $vm,
                $block,
                $op->arg2
            );
        }
        $constValue = \PHPCompiler\VM\EnumCaseSupport::materializeConstantValue(
            $this->context->runtime->vmContext,
            $constValue
        );
        if ($this->context->runtime->vmContext->defineConstant(
            $nameOp->value,
            $constValue
        )) {
            $this->registeredGlobalConstDeclareOpcodes->attach($op);
            if (\PHPCompiler\VM\Variable::TYPE_ARRAY === $constValue->type) {
                $this->context->constantArrayFromVmHashTable(
                    $nameOp->value,
                    $constValue->toArray()
                );
            } elseif (
                \PHPCompiler\VM\Variable::TYPE_OBJECT === $constValue->type
                && !\PHPCompiler\VM\EnumCaseSupport::isEnumCaseVariable($constValue)
            ) {
                // Bake immortal object for later CONST_FETCH (#35196, peer #34783).
                $this->context->constantObjectFromVm($nameOp->value, $constValue);
            }

            return;
        }
        // Spine may require bin/vm.php after tokenizer-compat shims (#2134).
        if ($this->shouldUseSelfHostJitStubs()) {
            return;
        }
        // Re-compile passes (jitCompileBlock + runQueue) may revisit DECLARE_GLOBAL_CONST (#4941).
        if ($this->registeredGlobalConstDeclareOpcodes->contains($op)) {
            return;
        }
        $scriptPath = $block->scriptPath();
        $line = (int) ($op->globalConstStartLine ?? 0);
        $this->context->runtime->vmContext->errors->triggerError(
            "Constant {$nameOp->value} already defined",
            \PHPCompiler\VM\ErrorReporter::E_WARNING,
            null !== $scriptPath && '' !== $scriptPath ? $scriptPath : null,
            $this->context->runtime->vmContext,
            null,
            $line > 0 ? $line : 0
        );
    }
}
