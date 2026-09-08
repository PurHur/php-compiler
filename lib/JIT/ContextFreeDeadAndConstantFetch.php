<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Lint\UnsupportedFeature;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCompiler\Web\Superglobals;
use PHPLLVM;

/**
 * Dead-temp free + CONST_FETCH materialization for {@see Context} (#36387).
 *
 * Extracted from {@see ContextVariableOperandBinding} so freeDeadVariables /
 * constantFetch (and stdio/zend/enum helpers) stay a separate TU from operand→Variable
 * binding (split-TU / size-budget ratchet toward ContextVariableOperandBinding ≤ 1.2k
 * lines, #36199 / #36403).
 *
 * Used via {@code use ContextFreeDeadAndConstantFetch;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: temporary dtor after ZEND_ASSIGN / at return
 * (Zend/zend_execute.c) and zend_get_constant_str (Zend/zend_constants.c) live beside
 * the executor rather than inside CV slot binding.
 */
trait ContextFreeDeadAndConstantFetch
{
    public function freeDeadVariables(
        PHPLLVM\Value\Function_ $func,
        PHPLLVM\BasicBlock $basicBlock,
        Block $block,
        ?Operand $skipOperand = null
    ): void {
        // Callers pass the BB that owns the frees. NestedJIT may have cleared insert —
        // re-park on that BB when it is still open. Do not jump to an unrelated lastOpen
        // (dominate failures on mid-fn value boxes, #36382).
        $insert = BasicBlockHelper::tryGetInsertBlock($this);
        if (null === $insert) {
            if (null !== $basicBlock->getTerminator()) {
                return;
            }
            $this->builder->positionAtEnd($basicBlock);
        } elseif (null !== $insert->getTerminator()) {
            if (null === $basicBlock->getTerminator()) {
                $this->builder->positionAtEnd($basicBlock);
            } else {
                BasicBlockHelper::ensureOpenInsertBlock($this, 'free_dead_vars_cont');
            }
        }
        $coalesceResults = new \SplObjectStorage();
        foreach ($block->opCodes as $blockOp) {
            if (OpCode::TYPE_COALESCE === $blockOp->type && null !== $blockOp->block3) {
                $coalesceResults[$block->getOperand($blockOp->arg1)] = true;
            }
        }
        $returnOperands = new \SplObjectStorage();
        $returnSlots = [];
        foreach ($block->opCodes as $blockOp) {
            // TYPE_THROW must keep its operand alive the same way RETURN does: uncaught
            // emitThrow calls freeDeadVariables before instanceof Throwable, and freeing the
            // Exception object made `return throw new …` / `fn()=>throw new …` look like a
            // non-Throwable (or SIGSEGV) under AOT (#34868, peer #34859).
            if (
                (OpCode::TYPE_RETURN !== $blockOp->type && OpCode::TYPE_THROW !== $blockOp->type)
                || null === $blockOp->arg1
            ) {
                continue;
            }
            $returnOp = $block->getOperand($blockOp->arg1);
            $returnOperands[$returnOp] = true;
            $returnSlots[(int) $blockOp->arg1] = true;
        }
        if (null !== $skipOperand) {
            $returnOperands[$skipOperand] = true;
            $skipSlot = $block->slotForOperand($skipOperand);
            if (null !== $skipSlot) {
                $returnSlots[$skipSlot] = true;
            }
        }
        foreach ($this->coalesceAssignTargets as $mergeOp) {
            $returnOperands[$mergeOp] = true;
            $mergeSlot = $block->slotForOperand($mergeOp);
            if (null !== $mergeSlot) {
                $returnSlots[$mergeSlot] = true;
            }
        }
        // Match/?: echo merge stack slots must survive trailing JUMPIF in the same
        // block (second `echo match` after the first merge) (#24143).
        foreach ($this->coalesceMergeSlotOperands as $mergeSlot => $mergeSlotOp) {
            $returnOperands[$mergeSlotOp] = true;
            $returnSlots[(int) $mergeSlot] = true;
            $resolved = $block->slotForOperand($mergeSlotOp);
            if (null !== $resolved) {
                $returnSlots[$resolved] = true;
            }
        }
        // Pending call args already SENDed must survive later ?? / sub-block freeDead
        // before FUNCCALL_EXEC (e.g. `new App(self::make(), self::$o ?? null)` — Slim
        // AppFactory::create). php-src zend_send_by_val keeps the zval on the VM stack
        // until DO_FCALL; freeing the producer temp here UAF'd the object (#36382).
        foreach ($this->scope->argOperands as $pendingArgOp) {
            if (!$pendingArgOp instanceof Operand) {
                continue;
            }
            $returnOperands[$pendingArgOp] = true;
            $pendingSlot = $block->slotForOperand($pendingArgOp);
            if (null !== $pendingSlot) {
                $returnSlots[$pendingSlot] = true;
            }
        }
        $returnVarNames = [];
        foreach ($returnOperands as $returnOp) {
            $name = OperandName::resolve($returnOp);
            if (null !== $name) {
                $returnVarNames[$name] = true;
            }
        }
        $isUserFunctionReturnVoid = false;
        if (null !== $block->func) {
            $fnName = $block->func->name;
            if ('{main}' !== $fnName && !str_ends_with($fnName, '::__destruct')) {
                foreach ($block->opCodes as $blockOp) {
                    if (
                        OpCode::TYPE_RETURN_VOID === $blockOp->type
                        || (OpCode::TYPE_RETURN === $blockOp->type && null === $blockOp->arg1)
                    ) {
                        $isUserFunctionReturnVoid = true;
                        break;
                    }
                }
            }
        }
        foreach ($block->orig->deadOperands as $op) {
            if ($isUserFunctionReturnVoid) {
                // releaseJitFunctionLocalsAtReturn owns named CV delref. Shadow
                // allocas of those CVs must not delref again. Distinct NEW-result
                // temps keep their own addref and must fall through to free()
                // (Zend/zend_execute.c temp dtor after ZEND_ASSIGN; #36245).
                $name = OperandName::resolve($op);
                if (null !== $name && '' !== $name) {
                    continue;
                }
                if (!$this->scope->variables->contains($op)) {
                    continue;
                }
                $var = $this->scope->variables[$op];
                if (
                    Variable::TYPE_OBJECT === $var->type
                    && Variable::KIND_VARIABLE === $var->kind
                    && null !== $var->value
                    && $this->objectMirrorSharesNamedCvAlloca($var)
                ) {
                    $slotTy = $var->value->typeOf();
                    if (\PHPLLVM\Type::KIND_POINTER === $slotTy->getKind()) {
                        $this->builder->store(
                            $slotTy->getElementType()->constNull(),
                            $var->value
                        );
                    }
                    continue;
                }
            }
            if ($returnOperands->contains($op)) {
                continue;
            }
            $deadSlot = $block->slotForOperand($op);
            if (null !== $deadSlot && isset($returnSlots[$deadSlot])) {
                continue;
            }
            $name = OperandName::resolve($op);
            if (null !== $name && isset($returnVarNames[$name])) {
                continue;
            }
            // releaseJitFunctionLocalsAtReturn already delref'd named CVs; freeing
            // them again here drops orphan cycles to refcount 0 (#36245 scope_exit).
            if ($isUserFunctionReturnVoid && null !== $name && '' !== $name) {
                continue;
            }
            if ($coalesceResults->contains($op)) {
                continue;
            }
            if (!$this->scope->variables->contains($op)) {
                continue;
            }
            $var = $this->scope->variables[$op];
            $name = OperandName::resolve($op);
            if (
                null !== $var->superglobalName
                || (null !== $name && Superglobals::isSuperglobalName($name))
                || 'this' === $name
            ) {
                continue;
            }
            $var->free();
        }
    }

    /**
     * CLI stdio constants lower to integer fds for fwrite/standalone AOT (#90953, #10163).
     * VmStdStreamConstants registers stream objects on the VM; JIT must not see TYPE_OBJECT.
     */
    private function vmStdioFdVariable(string $name): ?VMVariable
    {
        return match ($name) {
            'STDIN' => $this->vmIntegerConstant(0),
            'STDOUT' => $this->vmIntegerConstant(1),
            'STDERR' => $this->vmIntegerConstant(2),
            default => null,
        };
    }

    private function vmIntegerConstant(int $value): VMVariable
    {
        $var = new VMVariable(VMVariable::TYPE_INTEGER);
        $var->int($value);

        return $var;
    }

    private function zendConstantVariable(string $name): ?VMVariable
    {
        if (!\is_string($name) || !\defined($name)) {
            return null;
        }
        $value = \constant($name);
        if (\is_int($value)) {
            return $this->vmIntegerConstant($value);
        }
        if (\is_float($value)) {
            $var = new VMVariable(VMVariable::TYPE_FLOAT);
            $var->float($value);

            return $var;
        }
        if (\is_bool($value)) {
            $var = new VMVariable(VMVariable::TYPE_BOOLEAN);
            $var->bool($value);

            return $var;
        }
        if (\is_string($value)) {
            $var = new VMVariable(VMVariable::TYPE_STRING);
            $var->string($value);

            return $var;
        }
        if (\is_resource($value)) {
            $stdio = $this->vmStdioFdVariable($name);
            if (null !== $stdio) {
                return $stdio;
            }
            // Other stream resources are unused in bundled bootstrap fixtures.
            $var = new VMVariable(VMVariable::TYPE_NULL);

            return $var;
        }

        return null;
    }

    /**
     * File-scope {@code const X = E::A} / define() holding an enum case (#34783).
     *
     * Class-const path rematerializes via {@see VmConstantJit} (#31967); global CONST_FETCH
     * previously only lowered scalars and threw on VM TYPE_OBJECT / TYPE_ENUM_CASE.
     *
     * php-src: Zend/zend_constants.c + Zend/zend_enum.c — file consts store case singletons.
     */
    private function constantFetchEnumCaseVariable(string $name, VMVariable $phpVar): Variable
    {
        if (VMVariable::TYPE_ENUM_CASE === $phpVar->type) {
            $var = VmConstantJit::toVariable($this, $phpVar);
            $var->compileTimeConstantName = $name;

            return $var;
        }
        $enumClass = \PHPCompiler\VM\EnumCaseSupport::enumClassForCaseVariable($phpVar);
        $caseName = \PHPCompiler\VM\EnumCaseSupport::enumCaseNameForVariable($phpVar);
        if (null === $enumClass || '' === $caseName) {
            throw new \LogicException('Enum case constant missing class/name: '.$name);
        }
        // Declared spelling for display name (#35332); lookup keys remain lowercase.
        $classId = $this->type->object->lookup(ltrim($enumClass->name, '\\'));
        $caseKey = \PHPCompiler\ClassConstName::key($caseName);
        $var = $this->type->object->jitEnumCaseFromBacking($classId, $caseKey);
        $var->compileTimeConstantName = $name;

        return $var;
    }

    public function constantFetch(Operand $op): ?Variable {
        if ($op instanceof Operand\Literal) {
            $name = $op->value;
        } else {
            UnsupportedFeature::raise('variable-constant-fetch-jit');
        }
        if (!isset($this->constants[$name])) {
            $phpVar = $this->runtime->vmContext->constantFetch($name);
            if (is_null($phpVar)) {
                $phpVar = $this->zendConstantVariable($name);
            } elseif (VMVariable::TYPE_OBJECT === $phpVar->type) {
                $stdio = $this->vmStdioFdVariable($name);
                if (null !== $stdio) {
                    $phpVar = $stdio;
                }
            }
            if (is_null($phpVar)) {
                return null;
            }
            // File-scope const / define() with enum case singleton (#34783, peer #31967).
            if (\PHPCompiler\VM\EnumCaseSupport::isEnumCaseVariable($phpVar)) {
                return $this->constantFetchEnumCaseVariable($name, $phpVar);
            }
            // File-scope const holding a user/builtin object (#35196, new-in-initializers).
            if (VMVariable::TYPE_OBJECT === $phpVar->type) {
                $global = $this->constantObjectFromVm($name, $phpVar);
                $var = new Variable(
                    $this,
                    Variable::TYPE_OBJECT,
                    Variable::KIND_VALUE,
                    $this->builder->load($global)
                );
                $var->compileTimeConstantName = $name;

                return $var;
            }
            // convert to PHP variable
            switch ($phpVar->type) {
                case VMVariable::TYPE_NULL:
                    // Match Variable::fromLiteral TYPE_NULL — a real __value__ box, not
                    // nullptr. Catch-body / assign paths load through the pointer (#34659).
                    $slot = JitValueBox::alloc($this);
                    $this->builder->call(
                        $this->lookupFunction('__value__writeNull'),
                        JitValueBox::pointer($this, $slot)
                    );
                    $nullVar = new Variable(
                        $this,
                        Variable::TYPE_VALUE,
                        Variable::KIND_VARIABLE,
                        $slot
                    );
                    $nullVar->isNullConstant = true;

                    return $nullVar;
                case VMVariable::TYPE_INTEGER:
                    $type = $this->getTypeFromString('int64');
                    $global = $this->module->addGlobal($type, $name);
                    $intVal = $phpVar->toInt();
                    $global->setInitializer($type->constInt($intVal, false));
                    // [type, global, compileTimeLong] — foldable after Instruction load (#26774).
                    $this->constants[$name] = [Variable::TYPE_NATIVE_LONG, $global, $intVal];
                    break;
                case VMVariable::TYPE_FLOAT:
                    $type = $this->getTypeFromString('double');
                    $global = $this->module->addGlobal($type, $name);
                    $floatVal = $phpVar->toFloat();
                    $global->setInitializer($this->constantFromFloat($floatVal));
                    // Keep host float for compile-time fold (round(M_PI, 5), #27249).
                    $this->constants[$name] = [Variable::TYPE_NATIVE_DOUBLE, $global, $floatVal];
                    break;
                case VMVariable::TYPE_BOOLEAN:
                    $type = $this->getTypeFromString('int1');
                    $global = $this->module->addGlobal($type, $name);
                    $boolAsLong = $phpVar->toBool() ? 1 : 0;
                    $global->setInitializer($type->constInt($boolAsLong, false));
                    $this->constants[$name] = [Variable::TYPE_NATIVE_BOOL, $global, $boolAsLong];
                    break;
                case VMVariable::TYPE_STRING:
                    $compileTimeStr = $phpVar->toString();
                    $global = $this->constantStringFromString($compileTimeStr);
                    $this->constants[$name] = [Variable::TYPE_STRING, $global, $compileTimeStr];
                    break;
                case VMVariable::TYPE_ARRAY:
                    $global = $this->constantArrayFromVmHashTable($name, $phpVar->toArray());
                    $this->constants[$name] = [Variable::TYPE_VALUE, $global];
                    break;
                default:
                    throw new \LogicException("Non-implemented constant fetch type: " . $phpVar->type);
            }       
        }
        $var = new Variable(
            $this,
            $this->constants[$name][0],
            Variable::KIND_VALUE,
            $this->builder->load($this->constants[$name][1])
        );
        $var->compileTimeConstantName = $name;
        // true/false (and int) CONST_FETCH loads are Instruction-backed; keep a foldable
        // scalar so user-script AOT (XMLWriter::outputMemory(true), flush(false), …) can
        // treat them as compile-time flags (#26774, peer #23427 ARG_SEND rematerialize).
        if ((Variable::TYPE_NATIVE_BOOL === $this->constants[$name][0]
                || Variable::TYPE_NATIVE_LONG === $this->constants[$name][0])
            && isset($this->constants[$name][2])
            && \is_int($this->constants[$name][2])
        ) {
            $var->compileTimeLong = $this->constants[$name][2];
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $this->constants[$name][0]
            && isset($this->constants[$name][2])
            && \is_float($this->constants[$name][2])
        ) {
            $var->compileTimeFloat = $this->constants[$name][2];
        }
        if (Variable::TYPE_STRING === $this->constants[$name][0]
            && isset($this->constants[$name][2])
            && \is_string($this->constants[$name][2])
        ) {
            $var->compileTimeString = $this->constants[$name][2];
        }

        return $var;
    }
}
