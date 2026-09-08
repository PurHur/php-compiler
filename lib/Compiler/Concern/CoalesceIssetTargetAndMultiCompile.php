<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;
use PHPCompiler\VM\Variable;

/**
 * Coalesce isset-target finders + multi-var isset compile (#36387 / #36403).
 *
 * Extracted from {@see IssetEmptyCallArgAndMultiCompile} so gen-0 split-TU can
 * hollow a smaller Concern TU. Mirrors php-src Zend/zend_compile.c
 * zend_compile_isset_or_isempty (multi-var → ISSET_ISEMPTY_*) and coalesce-left
 * target resolution for isset/empty. Move-only; no behavior change intended.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as IssetEmptyCallArgAndMultiCompile).
 */
trait CoalesceIssetTargetAndMultiCompile
{
    protected function findCoalesceArrayDimFetch(?Operand $operand, Block $block): ?Op\Expr\ArrayDimFetch
    {
        if (null === $operand) {
            return null;
        }
        $direct = $this->unwrapArrayDimFetch($operand);
        if (null !== $direct) {
            return $direct;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ArrayDimFetch && $child->result === $operand) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return ?Op\Expr\PropertyFetch
     */
    protected function findCoalescePropertyFetch(?Operand $operand, Block $block): ?Op\Expr\PropertyFetch
    {
        if (null === $operand) {
            return null;
        }
        $direct = $this->unwrapPropertyFetch($operand);
        if (null !== $direct) {
            return $direct;
        }
        $candidates = [$operand];
        $seen = [];
        while ([] !== $candidates) {
            $current = array_shift($candidates);
            if (isset($seen[spl_object_id($current)])) {
                continue;
            }
            $seen[spl_object_id($current)] = true;
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\PropertyFetch && $child->result === $current) {
                    return $child;
                }
            }
            if ($current instanceof Temporary && null !== $current->original) {
                $candidates[] = $current->original;
            }
        }

        return null;
    }

    /**
     * @return ?Op\Expr\StaticPropertyFetch
     */
    protected function findCoalesceStaticPropertyFetch(?Operand $operand, Block $block): ?Op\Expr\StaticPropertyFetch
    {
        if (null === $operand) {
            return null;
        }
        $direct = $this->unwrapStaticPropertyFetch($operand);
        if (null !== $direct) {
            return $direct;
        }
        $candidates = [$operand];
        $seen = [];
        while ([] !== $candidates) {
            $current = array_shift($candidates);
            if (isset($seen[spl_object_id($current)])) {
                continue;
            }
            $seen[spl_object_id($current)] = true;
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\StaticPropertyFetch && $child->result === $current) {
                    return $child;
                }
            }
            if ($current instanceof Temporary && null !== $current->original) {
                $candidates[] = $current->original;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTargetFromPropertyFetch(Op\Expr\PropertyFetch $fetch, Block $block): array
    {
        return [
            $this->compileOperand($fetch->var, $block, true),
            $this->compileOperand($fetch->name, $block, true),
        ];
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTargetFromStaticPropertyFetch(
        Op\Expr\StaticPropertyFetch $fetch,
        Block $block
    ): array {
        return [
            $this->compileClassNameOperand($fetch->class, $block),
            $this->compileStaticPropertyNameSlot($fetch->name, $fetch->class, $block),
        ];
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTargetFromArrayDimFetch(Op\Expr\ArrayDimFetch $fetch, Block $block): array
    {
        return [
            $this->compileOperand($fetch->var, $block, true),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null,
        ];
    }

    protected function makeIssetOpCode(
        int $resultSlot,
        int $containerSlot,
        ?int $dimSlot,
        bool $issetOnProperty
    ): OpCode {
        $op = new OpCode(OpCode::TYPE_ISSET, $resultSlot, $containerSlot, $dimSlot);
        $op->issetOnProperty = $issetOnProperty;

        return $op;
    }

    protected function unwrapVariableOperand(Operand $operand): ?Operand\Variable
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Operand\Variable) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Operand\Variable) {
            return $operand;
        }

        return null;
    }

    /**
     * isset($a, $b, …) with short-circuit evaluation (PHP semantics).
     * Returns the block where compilation should continue.
     */
    protected function compileIssetMulti(Op\Expr\Isset_ $expr, Block $block): Block
    {
        // Nested dim chains under multi-arg isset (`isset($m[0]["v"], $m[0]["t"])`) must
        // reuse single-arg compileIsset lowering. The multi JUMPIF + ASSIGN path recycled
        // FETCH_DIM_W/`$m[0]=…` hash bucket cells as the isset result and turned `$m[0]`
        // into bool (#36398). Flat multi-arg (no nested dims) keeps the fast path below.
        foreach ($expr->vars as $var) {
            $dimFetch = $this->findCoalesceArrayDimFetch($var, $block);
            if (null === $dimFetch) {
                continue;
            }
            if (count($this->collectArrayDimFetchChain($dimFetch, $block)) > 1) {
                return $this->compileIssetMultiViaSingles($expr, $block);
            }
        }

        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $falseSlot = $this->compileBoolConstant($block, false);
        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);
        $falseBlock = new Block($block->orig);
        $falseBlock->inheritUndefinedLocals = true;
        $falseBlock->inheritScopeFrom($block);
        $falseBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $falseSlot
        ));
        $falseJump = new OpCode(OpCode::TYPE_JUMP);
        $falseJump->block1 = $endBlock;
        $falseBlock->addOpCode($falseJump);
        $endBlock->parents[] = $falseBlock;

        $current = $block;
        $vars = $expr->vars;
        $last = count($vars) - 1;
        foreach ($vars as $i => $var) {
            $this->assertIssetVariableOperand($var, $block);
            $propFetch = $this->findCoalescePropertyFetch($var, $block);
            $staticPropFetch = null !== $propFetch
                ? null
                : $this->findCoalesceStaticPropertyFetch($var, $block);
            $dimFetch = null !== $propFetch || null !== $staticPropFetch
                ? null
                : $this->findCoalesceArrayDimFetch($var, $block);
            $checkSlot = $resultSlot;
            if ($i < $last) {
                $checkSlot = $this->compileBoolTemporary($current);
            }
            if (null !== $dimFetch) {
                // Nested dims need the same FETCH_DIM_IS prefix as single-arg compileIsset (#36398).
                // resolveIssetTargetFromArrayDimFetch only sees the innermost fetch's var (a temp),
                // so isset($m[0]["v"], …) was always false when assigned.
                //
                // Use fresh intermediate slots (not php-cfg fetch->result): multi-arg JUMPIF
                // blocks reuse VM scope slots, and writing the isset bool into a recycled
                // FETCH_DIM_IS dest left `$m[0]` reads as bool true (#36398 / #36380 class).
                $chain = $this->collectArrayDimFetchChain($dimFetch, $current);
                foreach ($chain as $chainFetch) {
                    $this->rejectArrayEmptyOffsetRead($chainFetch, $current);
                }
                [$prefixOps, $containerSlot] = $this->emitQuietDimFetchChainPrefixFresh($chain, $current);
                foreach ($prefixOps as $prefixOp) {
                    $current->addOpCode($prefixOp);
                }
                $lastFetch = $chain[count($chain) - 1];
                $dimSlot = null !== $lastFetch->dim
                    ? $this->compileOperand($lastFetch->dim, $current, true)
                    : null;
                // Always write into a fresh bool temp, then assign to the isset result on the
                // last arm — keeps resultSlot free of dim-indirect aliasing.
                $issetDest = $i < $last ? $checkSlot : $this->compileBoolTemporary($current);
                if ($i === $last) {
                    $checkSlot = $issetDest;
                }
                $current->addOpCode($this->makeIssetOpCode($issetDest, $containerSlot, $dimSlot, false));
                if ($i === $last) {
                    $current->addOpCode(new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $resultSlot,
                        $resultSlot,
                        $issetDest
                    ));
                }
            } elseif (null !== $propFetch || null !== $staticPropFetch) {
                [$containerSlot, $dimSlot] = null !== $propFetch
                    ? $this->resolveIssetTargetFromPropertyFetch($propFetch, $current)
                    : $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $current);
                $issetOp = $this->makeIssetOpCode(
                    $checkSlot,
                    $containerSlot,
                    $dimSlot,
                    null !== $propFetch
                );
                if (null !== $staticPropFetch) {
                    $issetOp->issetOnStaticProperty = true;
                }
                $current->addOpCode($issetOp);
            } else {
                [$containerSlot, $dimSlot] = $this->resolveIssetTarget($var, $current);
                if (null === $containerSlot) {
                    $varSlot = $this->compileOperand($var, $current, true);
                    $current->addOpCode(new OpCode(OpCode::TYPE_ISSET, $checkSlot, $varSlot, null));
                } else {
                    $current->addOpCode($this->makeIssetOpCode($checkSlot, $containerSlot, $dimSlot, false));
                }
            }
            if ($i < $last) {
                $next = new Block($block->orig);
                $next->inheritUndefinedLocals = true;
                $next->inheritScopeFrom($current);
                $jump = new OpCode(OpCode::TYPE_JUMPIF, $checkSlot);
                $jump->block1 = $next;
                $jump->block2 = $falseBlock;
                $next->parents[] = $current;
                $falseBlock->parents[] = $current;
                $current->addOpCode($jump);
                $current = $next;
            }
        }

        $doneJump = new OpCode(OpCode::TYPE_JUMP);
        $doneJump->block1 = $endBlock;
        $current->addOpCode($doneJump);
        $endBlock->parents[] = $current;

        return $endBlock;
    }

    /**
     * Desugar multi-arg isset to short-circuit AND of single-arg compileIsset (#36398).
     *
     * php-src: Zend/zend_compile.c zend_compile_isset_or_isempty (multi-var → ISSET_ISEMPTY_*).
     */
    protected function compileIssetMultiViaSingles(Op\Expr\Isset_ $expr, Block $block): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $falseSlot = $this->compileBoolConstant($block, false);
        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);
        $falseBlock = new Block($block->orig);
        $falseBlock->inheritUndefinedLocals = true;
        $falseBlock->inheritScopeFrom($block);
        $falseBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $falseSlot
        ));
        $falseJump = new OpCode(OpCode::TYPE_JUMP);
        $falseJump->block1 = $endBlock;
        $falseBlock->addOpCode($falseJump);
        $endBlock->parents[] = $falseBlock;

        $current = $block;
        $vars = $expr->vars;
        $last = count($vars) - 1;
        foreach ($vars as $i => $var) {
            $checkOperand = new Temporary;
            $checkOperand->type = Type::bool();
            $checkOperand->usages[] = $checkOperand;
            $synthetic = new Op\Expr\Isset_([$var]);
            $synthetic->result = $checkOperand;
            $checkOperand->usages[] = $synthetic;
            foreach ($this->compileIsset($synthetic, $current) as $op) {
                $current->addOpCode($op);
            }
            $checkSlot = $current->getVarSlot($checkOperand, true);
            if ($i < $last) {
                $next = new Block($block->orig);
                $next->inheritUndefinedLocals = true;
                $next->inheritScopeFrom($current);
                $jump = new OpCode(OpCode::TYPE_JUMPIF, $checkSlot);
                $jump->block1 = $next;
                $jump->block2 = $falseBlock;
                $next->parents[] = $current;
                $falseBlock->parents[] = $current;
                $current->addOpCode($jump);
                $current = $next;
            } else {
                $current->addOpCode(new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $resultSlot,
                    $resultSlot,
                    $checkSlot
                ));
            }
        }

        $doneJump = new OpCode(OpCode::TYPE_JUMP);
        $doneJump->block1 = $endBlock;
        $current->addOpCode($doneJump);
        $endBlock->parents[] = $current;

        return $endBlock;
    }

    protected function compileBoolTemporary(Block $block): int
    {
        $operand = new Temporary;
        $operand->type = Type::bool();
        // JIT assignOperandValue skips operands with empty usages (#99 coalesce branches).
        $operand->usages[] = $operand;

        return $block->getVarSlot($operand, false);
    }

    protected function compileBoolConstant(Block $block, bool $value): int
    {
        $var = new Variable(Variable::TYPE_BOOLEAN);
        $var->bool($value);
        $operand = new Operand\Temporary;
        $operand->type = Type::bool();

        return $block->registerConstant($operand, $var);
    }

}
