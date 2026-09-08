<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Operand→Variable alias / scope-slot binding for {@see Context} (#36387).
 *
 * Extracted from {@see ContextVariableOperandBinding} so aliasVariableOp* /
 * functionScopeBindingVariable / ref-alias / foreach-by-ref name helpers stay a
 * separate TU from makeVariableFromOp + constant-slot bind (split-TU /
 * size-budget ratchet toward Binding ≤ 500 lines, #36199 / #36403).
 *
 * Used via {@code use ContextVariableOperandAlias;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: CV alias / temporary slot sharing in the
 * executor (Zend/zend_execute.c EX_VAR / CV fetch) lives beside allocate/bind
 * rather than inside the same translation unit forever.
 */
trait ContextVariableOperandAlias
{
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
}
