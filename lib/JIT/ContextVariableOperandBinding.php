<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\Web\Superglobals;
use PHPLLVM;

/**
 * Operand→Variable binding for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so makeVariableFromOp / constant-slot bind stay
 * a separate TU from the Context construction / compile hub.
 * Lookup (getVariableFromOp / $this seed) lives in {@see ContextVariableOperandLookup};
 * alias/scope-slot helpers live in {@see ContextVariableOperandAlias};
 * dead-temp free lives in {@see ContextFreeDeadAndConstantFetch}; CONST_FETCH in
 * {@see ContextConstantFetch} (split-TU / size-budget ratchet toward ≤ 500 lines,
 * #36199 / #36403).
 *
 * Used via {@code use ContextVariableOperandBinding;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: Zend CV/temporary slot resolution lives beside
 * the executor but outside the compiler front-end (Zend/zend_execute.c,
 * Zend/zend_execute_API.c).
 */
trait ContextVariableOperandBinding
{
    public function makeVariableFromOp(
        PHPLLVM\Value\Function_ $func,
        PHPLLVM\BasicBlock $basicBlock,
        Block $block,
        Operand $op
    ) {
        // Prefer the current block's folded constant even when a parent TYPE_TRY hoist
        // already allocated an empty placeholder for the same Temporary (#29751).
        if ($this->bindBlockConstantIfPresent($block, $op)) {
            return;
        }
        if ($this->scope->variables->contains($op)) {
            return;
        }
        $name = OperandName::resolve($op);
        if ('this' === $name) {
            foreach ($this->scope->variables as $existingOp) {
                if ('this' === OperandName::resolve($existingOp)) {
                    $this->scope->variables[$op] = $this->scope->variables[$existingOp];

                    return;
                }
            }
            // Inlined eval/include {main}: additional $this operands (hoisted vs scoped)
            // must alias the include-entry bind / LLVM param 0 (#31902 / #31903).
            if ($this->inlineIncludeDepth > 0) {
                $inheritedThis = $this->findThisVariable();
                if (null !== $inheritedThis && Variable::TYPE_OBJECT === $inheritedThis->type) {
                    $this->scope->variables[$op] = $inheritedThis;

                    return;
                }
                // Static/file-scope eval must not materialize $this as script global/alloca (#31902 AOT).
                return;
            }
        }
        if (null !== $name && Superglobals::isSuperglobalName($name)) {
            $this->scope->variables[$op] = SuperglobalInit::load($this, $name);

            return;
        }
        if (null !== $name && $block->isMainScript()) {
            // Inlined eval {main} without a bound caller $this must not become a script global (#31902 AOT).
            if ('this' !== $name && !$this->isForeachByRefLocalName($name, $block)) {
                $resolved = $this->resolveRefAliasName($name);
                if (isset($this->namedVariableBindings[$resolved])) {
                    $this->scope->variables[$op] = $this->namedVariableBindings[$resolved];

                    return;
                }
                $stayNative = $this->analyzer->canStayNativeLong($op);
                if (!$stayNative) {
                    foreach ($block->scopedOperands() as $other) {
                        if (!$other instanceof Operand || $other === $op) {
                            continue;
                        }
                        if ($name !== OperandName::resolve($other)) {
                            continue;
                        }
                        if ($this->analyzer->canStayNativeLong($other)) {
                            $stayNative = true;
                            break;
                        }
                    }
                }
                if (!$stayNative) {
                    $global = $this->ensureScriptGlobal($name);
                    $this->scope->variables[$op] = $global;
                    $this->bindVariableByName($name, $global);

                    return;
                }
                // Fall through to fromOp + shared bind — do not ensureScriptGlobal (#36408).
            }
        }
        $this->scope->variables[$op] = Variable::fromOp($this, $func, $basicBlock, $block, $op);
        $this->scope->variables[$op]->initialize();
        $this->recordScopeSlotObjectMirrorLlvm($block, $op, $this->scope->variables[$op]);
        if (
            null !== $name
            && '' !== $name
            && $block->isMainScript()
            && Variable::TYPE_NATIVE_LONG === $this->scope->variables[$op]->type
        ) {
            $this->bindVariableByName($name, $this->scope->variables[$op]);
        }
    }

    /**
     * Track primary {@see __object__**} entry allocas by CFG scope slot (#36245 loop_unset).
     */
    public function recordScopeSlotObjectMirrorLlvm(Block $block, Operand $op, Variable $var): void
    {
        if (Variable::TYPE_OBJECT !== $var->type || Variable::KIND_VARIABLE !== $var->kind) {
            return;
        }
        if ($var->functionStaticGlobal || null !== $var->objectPropertySlot) {
            return;
        }
        $storageTy = $this->getStringFromType($var->value->typeOf());
        if (!str_contains($storageTy, '__object__')) {
            return;
        }
        $cfgSlot = $block->slotForOperand($op);
        if (null === $cfgSlot) {
            return;
        }
        $this->scopeSlotObjectMirrorLlvmBySlot[(int) $cfgSlot] = $var->value;
    }

    /**
     * True when $var is a named CV or a shadow {@see __object__**} alloca of one.
     * Distinct NEW/ASSIGN result temps have their own addref and must be delref'd
     * (Zend temp dtor after ZEND_ASSIGN; #36245 scope_exit).
     */
    public function objectMirrorSharesNamedCvAlloca(Variable $var): bool
    {
        foreach ($this->namedVariableBindings as $bound) {
            if ($bound === $var) {
                return true;
            }
            if (null !== $bound->value && $bound->value === $var->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bind {@see Block::$constants} for $op when the slot is a folded literal (#29751).
     *
     * TYPE_TRY handler blocks hoist try-body Temporaries into scope before the try-body
     * block is lowered; those placeholders have no parent-block constant. Re-bind when the
     * body block's constant table has the UnaryMinus/Plus fold (e.g. {@code -1} for {@code <<}).
     */
    private function bindBlockConstantIfPresent(Block $block, Operand $op): bool
    {
        $slot = $block->slotForOperand($op);
        if (null === $slot || !isset($block->constants[$slot])) {
            return false;
        }
        // A Temporary rebound onto a FuncCall name-literal slot (ternary ?: phi sharing the
        // INIT name's index) must not rematerialize that name string (#34814).
        if ($op instanceof Operand\Temporary) {
            foreach ($block->scopedOperands() as $scopedOp) {
                if (
                    $block->slotForOperand($scopedOp) === $slot
                    && $scopedOp instanceof Operand\Literal
                    && $scopedOp !== $op
                ) {
                    return false;
                }
            }
        }
        // Function formals carry their default in ARG_RECV / call-site filling.
        // Rematerializing that constant as the CV makes `f($x = 1); f(7)` and
        // `__construct(public $x = 1); new C(7)` ignore the argument (#32349).
        if (null !== $block->func) {
            $opName = OperandName::resolve($op);
            foreach ($block->func->params as $param) {
                if ($param->result === $op) {
                    return false;
                }
                $paramName = OperandName::resolve($param->result);
                if (null !== $opName && null !== $paramName && $opName === $paramName) {
                    return false;
                }
            }
        }
        $constVm = $block->constants[$slot];
        // #28038 stopped treating named CVs as embedded name-string literals. Call-arg
        // lowering can still leave TYPE_STRING placeholders on the CV's real slot while
        // the Operand's CFG type is int/float/bool — NestedJIT then binds NATIVE_LONG
        // formals into STRING slots (MbNumericEntity encode4 $m0…$m3, #28053). Prefer
        // the declared CFG type when it disagrees with the compile-time constant.
        if (!$this->slotConstantAgreesWithOperandType($constVm, $op)) {
            return false;
        }
        $this->scope->variables[$op] = VmConstantJit::toVariable($this, $constVm);

        return true;
    }

    /**
     * True when {@see Block::$constants} may drive {@see makeVariableFromOp} for $op (#28053).
     */
    private function slotConstantAgreesWithOperandType(\PHPCompiler\VM\Variable $constVm, Operand $op): bool
    {
        // Enum/object compile-time slots must not be rematerialized during hoisted
        // makeVariableFromOp: that runs before DECLARE_ENUM/DECLARE_CLASS (#31967).
        // After the enum is registered, folded `C::K` / `E::X` slots (the script-level
        // CLASS_CONST_FETCH is often eliminated) must rematerialize the singleton.
        if (\PHPCompiler\VM\Variable::TYPE_ENUM_CASE === $constVm->type) {
            try {
                $case = $constVm->toEnumCase();
            } catch (\Throwable) {
                return false;
            }
            $enumLc = strtolower(ltrim($case->enumClass->name, '\\'));
            if (
                !$this->type->object->hasDeclaredClass($enumLc)
                || !$this->type->object->isRegisteredEnumLc($enumLc)
            ) {
                return false;
            }

            return true;
        }
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT === $constVm->type) {
            return false;
        }
        $declared = Variable::getTypeFromType($op->type ?? null);
        if (
            Variable::TYPE_VALUE === $declared
            || Variable::TYPE_NULL === $declared
        ) {
            return true;
        }
        $constJit = match ($constVm->type) {
            \PHPCompiler\VM\Variable::TYPE_INTEGER => Variable::TYPE_NATIVE_LONG,
            \PHPCompiler\VM\Variable::TYPE_STRING => Variable::TYPE_STRING,
            \PHPCompiler\VM\Variable::TYPE_FLOAT => Variable::TYPE_NATIVE_DOUBLE,
            \PHPCompiler\VM\Variable::TYPE_BOOLEAN => Variable::TYPE_NATIVE_BOOL,
            \PHPCompiler\VM\Variable::TYPE_NULL => Variable::TYPE_NULL,
            default => Variable::TYPE_VALUE,
        };
        if (Variable::TYPE_VALUE === $constJit) {
            return true;
        }

        return $declared === $constJit;
    }
}
