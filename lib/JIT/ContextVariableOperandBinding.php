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
 * Operand→Variable binding for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so makeVariableFromOp / getVariableFromOp /
 * alias/scope binding stay a separate TU from the Context construction / compile hub.
 * Dead-temp free + CONST_FETCH live in {@see ContextFreeDeadAndConstantFetch}
 * (split-TU / size-budget ratchet, #36199 / #36403).
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

    public function setVariableOp(Operand $op, Variable $var) {
        $this->scope->variables[$op] = $var;
    }

    /**
     * php-cfg may use distinct {@see Operand\Variable}/{@see Operand\Temporary} objects for one scope slot (#72, #12036).
     */
    private function aliasVariableOpByName(Operand $op): bool
    {
        $name = OperandName::resolve($op);
        if (null === $name || '' === $name) {
            return false;
        }
        $resolved = $this->resolveRefAliasName($name);
        if (isset($this->namedVariableBindings[$resolved])) {
            $this->scope->variables[$op] = $this->namedVariableBindings[$resolved];

            return true;
        }
        // CLI globals imported via `global $argv` / `global $argc` on inventory argv spine (#12036).
        if ('argv' === $name || 'argc' === $name) {
            $global = $this->ensureScriptGlobal($name);
            $alias = new Variable(
                $this,
                Variable::TYPE_VALUE,
                Variable::KIND_VARIABLE,
                JitValueBox::alloc($this)
            );
            $alias->valueBoxAliasPtr = JitValueBox::valuePtrFromVariable($this, $global);
            $alias->functionStaticGlobal = true;
            $this->bindVariableByName($name, $alias);
            $this->scope->variables[$op] = $alias;

            return true;
        }
        foreach ($this->scope->variables as $scopeOp) {
            if ($name === OperandName::resolve($scopeOp)) {
                $this->scope->variables[$op] = $this->scope->variables[$scopeOp];

                return true;
            }
        }
        foreach ($this->scopeStack as $scope) {
            foreach ($scope->variables as $scopeOp) {
                if ($name === OperandName::resolve($scopeOp)) {
                    $this->scope->variables[$op] = $scope->variables[$scopeOp];

                    return true;
                }
            }
        }
        $block = $this->jitCurrentBlock;
        if (null !== $block) {
            if ($block->declaresGlobalName($name)) {
                $global = $this->ensureScriptGlobal($name);
                $this->bindVariableByName($name, $global);
                $this->scope->variables[$op] = $global;

                return true;
            }
            $slot = $block->slotForOperand($op);
            if (null !== $slot) {
                foreach ($block->scopedOperands() as $scopeOp) {
                    if ($block->slotForOperand($scopeOp) !== $slot || !$this->scope->variables->contains($scopeOp)) {
                        continue;
                    }
                    $this->scope->variables[$op] = $this->scope->variables[$scopeOp];

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * php-cfg may use distinct {@see Operand\Temporary} objects for one scope slot (#72).
     */
    public function aliasVariableOpFromSlot(Block $block, Operand $op): bool
    {
        if ($this->scope->variables->contains($op)) {
            return true;
        }
        if ($this->aliasVariableOpByName($op)) {
            return true;
        }
        $slot = $block->slotForOperand($op);
        if (null === $slot) {
            return false;
        }
        // Prefer any Variable already bound for this slot in scope — ARG_SEND / json_encode
        // often use a distinct Temporary from ARRAY_SPREAD's dest (#28673). Searching only
        // scopedOperands() missed the spread rebind and allocated a fresh null value box.
        foreach ($this->scope->variables as $scopeOp) {
            if (!$scopeOp instanceof Operand) {
                continue;
            }
            if ($block->slotForOperand($scopeOp) !== $slot) {
                continue;
            }
            // ?: merge Temporary and FUNCCALL name Literal share a numeric slot after
            // bindScopeSlot (#34818). Aliasing the phi onto LITERAL('strlen') makes
            // `true ? strlen($s) : "bad"` echo the function name.
            if ($op instanceof Operand\Temporary && $scopeOp instanceof Operand\Literal) {
                continue;
            }
            $this->scope->variables[$op] = $this->scope->variables[$scopeOp];

            return true;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) !== $slot || !$this->scope->variables->contains($scopeOp)) {
                continue;
            }
            if ($op instanceof Operand\Temporary && $scopeOp instanceof Operand\Literal) {
                continue;
            }
            $this->scope->variables[$op] = $this->scope->variables[$scopeOp];

            return true;
        }

        return false;
    }

    /**
     * Resolve a CFG return/phi operand against the arm block's scope slots (#8555, #23482).
     *
     * Arm-tail ?: returns pass the merge RETURN operand while {@see $jitCurrentBlock} may
     * already have moved on; use $cfgBlock for slot aliasing. Null means the caller should
     * fall back to {@see getVariableFromOp}.
     */
    public function functionScopeBindingVariable(Operand $op, Block $cfgBlock): ?Variable
    {
        if ($this->scope->variables->contains($op)) {
            return $this->scope->variables[$op];
        }
        if ($this->aliasVariableOpFromSlot($cfgBlock, $op)) {
            return $this->scope->variables[$op];
        }
        $name = OperandName::resolve($op);
        if (null !== $name && '' !== $name) {
            $resolved = $this->resolveRefAliasName($name);
            if (isset($this->namedVariableBindings[$resolved])) {
                $this->scope->variables[$op] = $this->namedVariableBindings[$resolved];

                return $this->namedVariableBindings[$resolved];
            }
        }

        return null;
    }

    public function hasVariableOp(Operand $op): bool {
        if ($this->scope->variables->contains($op)) {
            return true;
        }
        if ($op instanceof Operand\Literal) {
            return true;
        }
        return false;
    }

    public function resolveRefAliasName(string $name): string
    {
        while (isset($this->refAliasNames[$name])) {
            $name = $this->refAliasNames[$name];
        }

        return $name;
    }

    /** True when $name is the dest of foreach Iterator_Value → AssignRef in $block (#4364). */
    public function isForeachByRefLocalName(string $name, Block $block): bool
    {
        $resolved = $this->resolveRefAliasName($name);
        if (isset($this->foreachByRefLocalNames[$resolved])) {
            return true;
        }
        $root = $this->jitFunctionRootBlock ?? $block;
        $seen = [];
        $queue = [$root];
        while ([] !== $queue) {
            $scan = array_shift($queue);
            $id = spl_object_id($scan);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($scan->opCodes as $op) {
                if (OpCode::TYPE_ASSIGN_REF === $op->type) {
                    $destName = OperandName::resolve($scan->getOperand($op->arg1));
                    if (null !== $destName && $resolved === $this->resolveRefAliasName($destName)) {
                        $srcName = OperandName::resolve($scan->getOperand($op->arg2));
                        if (null === $srcName) {
                            $this->foreachByRefLocalNames[$resolved] = true;

                            return true;
                        }
                    }
                }
                if (OpCode::TYPE_ITER_VALUE === $op->type && $op->arg3) {
                    $destName = OperandName::resolve($scan->getOperand($op->arg1));
                    if (null !== $destName && $resolved === $this->resolveRefAliasName($destName)) {
                        $this->foreachByRefLocalNames[$resolved] = true;

                        return true;
                    }
                }
                foreach ([$op->block1 ?? null, $op->block2 ?? null, $op->block3 ?? null] as $target) {
                    if ($target instanceof Block && !isset($seen[spl_object_id($target)])) {
                        $queue[] = $target;
                    }
                }
            }
        }

        return false;
    }

    public function getVariableFromOp(Operand $op): Variable {
        $name = OperandName::resolve($op);
        if (null !== $name && '' !== $name) {
            $resolved = $this->resolveRefAliasName($name);
            if ($resolved !== $name) {
                foreach ($this->scope->variables as $scopeOp) {
                    if (!$scopeOp instanceof Operand) {
                        continue;
                    }
                    if ($resolved === OperandName::resolve($scopeOp)) {
                        return $this->scope->variables[$scopeOp];
                    }
                }
            }
            if (isset($this->namedVariableBindings[$resolved])) {
                $this->scope->variables[$op] = $this->namedVariableBindings[$resolved];

                return $this->namedVariableBindings[$resolved];
            }
        }
        // Try-body folded constants (e.g. UnaryMinus → -1) must win over empty parent-hoist
        // placeholders already in scope (#29751 AOT `$a << -1` inside try).
        if ($op instanceof Operand\Temporary && null !== $this->jitCurrentBlock
            && $this->bindBlockConstantIfPresent($this->jitCurrentBlock, $op)) {
            return $this->scope->variables[$op];
        }
        if (!$this->scope->variables->contains($op)) {
            if ($op instanceof Operand\Literal) {
                $this->scope->variables[$op] = Variable::fromLiteral($this, $op);
            } elseif ($op instanceof Operand\BoundVariable
                && Operand\BoundVariable::SCOPE_OBJECT === $op->scope) {
                $thisVar = $this->findThisVariable();
                if (null === $thisVar) {
                    $thisVar = $this->seedImplicitThisFromActiveLlvmFunction();
                }
                if (null !== $thisVar) {
                    $this->scope->variables[$op] = $thisVar;

                    return $thisVar;
                }
                throw new \LogicException('BoundVariable SCOPE_OBJECT without $this in JIT scope');
            } elseif ($op instanceof Operand\BoundVariable && $op->name instanceof Operand) {
                if ($this->aliasVariableOpByName($op)) {
                    return $this->scope->variables[$op];
                }
                $inner = $this->getVariableFromOpInScopes($op->name);
                $this->scope->variables[$op] = $inner;

                return $inner;
            } elseif ($op instanceof Operand\BoundVariable) {
                throw new \LogicException(
                    'BoundVariable scope '.$op->scope
                    .' nameClass '.(is_object($op->name) ? get_class($op->name) : gettype($op->name))
                );
            } elseif ('this' === OperandName::resolve($op)) {
                $existing = $this->findThisVariable();
                if (null !== $existing) {
                    $this->scope->variables[$op] = $existing;
                } else {
                    throw new \LogicException("Unknown variable referenced: " . get_class($op));
                }
            } elseif ($op instanceof Operand\Temporary) {
                $block = $this->jitCurrentBlock;
                if (null !== $block) {
                    if ($this->aliasVariableOpFromSlot($block, $op)) {
                        return $this->scope->variables[$op];
                    }
                    $slot = $block->slotForOperand($op);
                    if (null !== $slot && null !== $block->func) {
                        foreach ($block->func->params as $param) {
                            $pname = OperandName::resolve($param->result);
                            if (null === $pname || '' === $pname) {
                                continue;
                            }
                            if ($block->slotForOperand($param->result) !== $slot) {
                                continue;
                            }
                            $resolved = $this->resolveRefAliasName($pname);
                            if (isset($this->namedVariableBindings[$resolved])) {
                                $bound = $this->namedVariableBindings[$resolved];
                                $this->scope->variables[$op] = $bound;

                                return $bound;
                            }
                        }
                    }
                }
                // Temporaries can be introduced by CFG transforms after scope variable allocation.
                // Treat unknown temporaries as boxed __value__ slots to keep self-host emit paths alive.
                $slot = JitValueBox::alloc($this);
                $this->builder->call(
                    $this->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($this, $slot)
                );
                $this->scope->variables[$op] = new Variable(
                    $this,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
            } elseif ($op instanceof Operand\Variable && $this->aliasVariableOpByName($op)) {
                // Distinct Variable operand for an already-allocated scope slot (#12036 inventory argv).
            } elseif ($op instanceof Operand\BoundVariable
                && Operand\BoundVariable::SCOPE_OBJECT === $op->scope) {
                $thisVar = $this->findThisVariable();
                if (null !== $thisVar) {
                    return $thisVar;
                }
                throw new \LogicException('BoundVariable SCOPE_OBJECT without $this in JIT scope');
            } else {
                throw new \LogicException("Unknown variable referenced: " . get_class($op));
            }
        }

        if ($this->scope->variables->contains($op)) {
            $bound = $this->resolveNamedBindingBySlot($op);
            if (null !== $bound) {
                $this->scope->variables[$op] = $bound;

                return $bound;
            }
            $block = $this->jitCurrentBlock;
            if (null !== $block && null !== $block->func) {
                $slot = $block->slotForOperand($op);
                if (null !== $slot) {
                    foreach ($block->func->params as $param) {
                        $pname = OperandName::resolve($param->result);
                        if (null === $pname || '' === $pname) {
                            continue;
                        }
                        if ($block->slotForOperand($param->result) !== $slot) {
                            continue;
                        }
                        $resolved = $this->resolveRefAliasName($pname);
                        if (isset($this->namedVariableBindings[$resolved])) {
                            $bound = $this->namedVariableBindings[$resolved];
                            $this->scope->variables[$op] = $bound;

                            return $bound;
                        }
                    }
                }
            }
        }

        $bound = $this->resolveNamedBindingBySlot($op);
        if (null !== $bound) {
            $this->scope->variables[$op] = $bound;

            return $bound;
        }

        return $this->scope->variables[$op];
    }

    /**
     * Loop headers reuse CFG slot numbers — prefer live named bindings over stale
     * compare temps so `$i < $len` reads the post-increment alloca (#36018 / #32605).
     */
    private function resolveNamedBindingBySlot(Operand $op): ?Variable
    {
        $block = $this->jitCurrentBlock ?? $this->jitEnclosingBlock;
        if (null === $block || null === $block->func) {
            return null;
        }
        $slot = $block->slotForOperand($op);
        if (null === $slot) {
            return null;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            $pname = OperandName::resolve($scopeOp);
            if (null === $pname || '' === $pname) {
                continue;
            }
            if ($block->slotForOperand($scopeOp) !== $slot) {
                continue;
            }
            $resolved = $this->resolveRefAliasName($pname);
            if (isset($this->namedVariableBindings[$resolved])) {
                return $this->namedVariableBindings[$resolved];
            }
        }

        return null;
    }

    public function findThisVariable(): ?Variable
    {
        foreach ($this->scope->variables as $existingOp) {
            if ('this' === OperandName::resolve($existingOp)) {
                return $this->scope->variables[$existingOp];
            }
        }
        if (null !== $this->implicitThisArgument) {
            return $this->implicitThisArgument;
        }

        return $this->seedImplicitThisFromActiveLlvmFunction();
    }

    /**
     * Queued nested instance methods may omit argVars; LLVM param 0 is $this (#16075).
     */
    public function seedImplicitThisFromActiveLlvmFunction(): ?Variable
    {
        if (null !== $this->implicitThisArgument) {
            return $this->implicitThisArgument;
        }
        $active = strtolower($this->activeFunction ?? '');
        if ('' === $active || !str_contains($active, '::')) {
            return null;
        }
        $llvmFn = $this->functions[$active] ?? null;
        if (null === $llvmFn || $llvmFn->countParams() < 1) {
            return null;
        }
        $thisParam = $llvmFn->getParam(0);
        $thisTy = $this->getStringFromType($thisParam->typeOf());
        if ('__object__*' !== $thisTy) {
            return null;
        }
        $this->implicitThisArgument = new Variable(
            $this,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $thisParam
        );

        return $this->implicitThisArgument;
    }

    public function hasVariableOpInScopes(Operand $op): bool
    {
        if ($this->scope->variables->contains($op)) {
            return true;
        }
        foreach ($this->scopeStack as $scope) {
            if ($scope->variables->contains($op)) {
                return true;
            }
        }

        return false;
    }

    public function getVariableFromOpInScopes(Operand $op): Variable
    {
        // Prefer by-ref / name rebinds over a stale same-object scope entry. php-cfg
        // uses distinct SSA Vars for `$n` (assign vs ARG_SEND vs echo); SEND_REF
        // updates namedVariableBindings while the echo operand may still hold the
        // pre-call constant (#24162).
        $name = OperandName::resolve($op);
        if (null !== $name && '' !== $name) {
            $resolved = $this->resolveRefAliasName($name);
            if (isset($this->namedVariableBindings[$resolved])) {
                $bound = $this->namedVariableBindings[$resolved];
                $this->scope->variables[$op] = $bound;

                return $bound;
            }
        }
        if ($this->scope->variables->contains($op)) {
            return $this->scope->variables[$op];
        }
        foreach ($this->scopeStack as $scope) {
            if ($scope->variables->contains($op)) {
                return $scope->variables[$op];
            }
        }

        return $this->getVariableFromOp($op);
    }

    public function makeVariableFromValueOp(
        PHPLLVM\Value $value,
        Operand $op
    ): Variable {
        $this->scope->variables[$op] = Variable::fromValueOp(
            $this, $value, $op
        );
        return $this->scope->variables[$op];
    }
}
