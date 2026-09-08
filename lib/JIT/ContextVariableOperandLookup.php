<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPLLVM;

/**
 * Operand→Variable lookup for {@see Context} (#36387).
 *
 * Extracted from {@see ContextVariableOperandBinding} so getVariableFromOp /
 * $this seeding / scope-stack lookup stay a separate TU from make/alias/bind
 * helpers (split-TU / size-budget ratchet toward ContextVariableOperandBinding
 * ≤ 500 lines, #36199 / #36403).
 *
 * Used via {@code use ContextVariableOperandLookup;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: CV/temporary fetch in the executor
 * (Zend/zend_execute.c zend_get_compiled_variable / EX_VAR) lives beside
 * allocate/bind rather than inside the same translation unit forever.
 */
trait ContextVariableOperandLookup
{
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
