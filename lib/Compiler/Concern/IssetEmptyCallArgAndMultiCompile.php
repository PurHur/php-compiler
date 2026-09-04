<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;
use PHPCompiler\VM\Variable;

/**
 * Isset/empty call-arg hoisting + multi-var isset compile (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see resolveCoalesceIssetTarget}, empty/isset call-arg recovery and
 * hoisted prelude slots, coalesce left finders for isset targets,
 * {@see compileIssetMulti}, and bool temporary/constant helpers.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as CompileCallArgSends / EchoCoalesceCallArgCompile).
 */
trait IssetEmptyCallArgAndMultiCompile
{
    /**
     * @return ?array{0: int, 1: ?int}
     */
    protected function resolveCoalesceIssetTarget(Operand $operand, Block $block): ?array
    {
        $fetch = $this->findCoalesceArrayDimFetch($operand, $block);
        if (null !== $fetch) {
            return $this->resolveIssetTargetFromArrayDimFetch($fetch, $block);
        }
        $propFetch = $this->findCoalescePropertyFetch($operand, $block);
        if (null !== $propFetch) {
            return $this->resolveIssetTargetFromPropertyFetch($propFetch, $block);
        }
        $staticPropFetch = $this->findCoalesceStaticPropertyFetch($operand, $block);
        if (null !== $staticPropFetch) {
            return $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $block);
        }
        if (null !== $this->unwrapVariableOperand($operand)) {
            return $this->resolveIssetTarget($operand, $block);
        }

        return null;
    }

    /**
     * @return ?Op\Expr\ArrayDimFetch
     */
    /**
     * php-cfg emits PropertyFetch before Empty_; recover operand when Empty_.expr is cleared (#4701, #6829).
     */
    private function recoverEmptyExprOperand(Op\Expr\Empty_ $expr, Block $block): ?Operand
    {
        if (null !== $expr->expr) {
            return $expr->expr;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\PropertyFetch && $this->isPropertyFetchOnlyEmptyVar($child, $expr, $block)) {
                return $child->result;
            }
            if ($child instanceof Op\Expr\StaticPropertyFetch && $this->isStaticPropertyFetchOnlyEmptyVar($child, $expr, $block)) {
                return $child->result;
            }
            if ($child instanceof Op\Expr\ArrayDimFetch && $this->isArrayDimFetchOnlyEmptyVar($child, $expr, $block)) {
                return $child->result;
            }
        }
        $funcCallFetch = $this->recoverEmptyPropertyFetchForFuncCallArg($expr, $block);
        if (null !== $funcCallFetch) {
            return $funcCallFetch;
        }

        return null;
    }

    /**
     * PropertyFetch hoisted before FuncCall(empty($obj->prop)) when php-cfg omits Empty_ stmt (#8901).
     */
    private function recoverEmptyPropertyFetchForFuncCallArg(Op\Expr\Empty_ $expr, Block $block): ?Operand
    {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        foreach ($children as $i => $child) {
            if (!$this->isInlineExprCallArgConsumer($child) || !$this->funcCallArgReferencesEmpty($child, $expr)) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; --$j) {
                $prev = $children[$j];
                if ($prev instanceof Op\Expr\PropertyFetch && $this->emptyExprDependsOnOperand($expr, $prev->result, $block)) {
                    return $prev->result;
                }
                if ($prev === $expr) {
                    continue;
                }
                if ($prev instanceof Op\Expr && $this->isInlineExprCallArgProducer($prev)) {
                    continue;
                }
                break;
            }
        }

        return null;
    }

    private function funcCallArgReferencesEmpty(Op $call, Op\Expr\Empty_ $empty): bool
    {
        if (!property_exists($call, 'args') || !is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            if ($arg instanceof Operand\Temporary && $arg->original === $empty) {
                return true;
            }
            if ($this->operandsReferToSameVariable($arg, $empty->result)) {
                return true;
            }
        }

        return false;
    }

    private function emptyExprDependsOnOperand(Op\Expr\Empty_ $expr, Operand $operand, Block $block): bool
    {
        $target = $this->unaryExprOperandForRead($expr, $block) ?? $expr->expr;
        if (null === $target) {
            return false;
        }
        if ($target === $operand) {
            return true;
        }

        return $this->operandsReferToSameVariable($target, $operand);
    }

    /**
     * @return ?Op\Expr\Empty_
     */
    private function findEmptyExprForCallArg(Operand $arg, Block $block): ?Op\Expr\Empty_
    {
        $empty = $this->unwrapEmptyExpr($arg);
        if (null !== $empty) {
            return $empty;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Empty_ && $this->operandsReferToSameVariable($child->result, $arg)) {
                return $child;
            }
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg);
        if (null === $callSite) {
            return null;
        }
        [$callOp, $argIndex] = $callSite;
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (null === $callArg) {
            return null;
        }

        return $this->unwrapEmptyExpr($callArg);
    }

    /**
     * @return ?Op\Expr\Empty_
     */
    private function unwrapEmptyExpr(Operand $operand): ?Op\Expr\Empty_
    {
        if ($operand instanceof Op\Expr\Empty_) {
            return $operand;
        }
        if ($operand instanceof Operand\Temporary) {
            if ($operand->original instanceof Op\Expr\Empty_) {
                return $operand->original;
            }
            if (null !== $operand->original) {
                return $this->unwrapEmptyExpr($operand->original);
            }
        }

        return null;
    }

    /**
     * FuncCall(empty($obj->prop)) — compile hoisted Empty_ when php-cfg left the arg slot dead (#8901).
     */
    private function compileHoistedEmptyCallArg(Operand $arg, Block $block): ?int
    {
        $empty = $this->findEmptyExprForCallArg($arg, $block);
        if (null === $empty) {
            return null;
        }
        if (!$this->emptyExprLoweringEmitted($block, $empty)) {
            foreach ($this->compileExpr($empty, $block) as $op) {
                $block->addOpCode($op);
            }
        }

        return $this->compileOperand($empty->result, $block, true);
    }

    /**
     * php-cfg dead call-arg temps for hoisted isset()/empty() — map to producer result slot (#11498).
     *
     * Sibling isset()/empty() before another expression in the same array literal must not steal
     * that call's literal/CV args (#25188).
     */
    private function resolveHoistedIssetOrEmptyCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?int {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        $producer = $this->findHoistedIssetOrEmptyProducerForCallArg($block, $cfgCallOp, $argIndex);
        if (null === $producer) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
        $argIsProducerResult = $this->operandsReferToSameVariable($producer->result, $callArg);
        $argIsDeadTemp = $this->callArgIsDeadInlineTemporary($callArg);
        if (!$argIsDeadTemp && !$argIsProducerResult) {
            // [isset($a['x']), array_key_exists('x', $a)] — preceding Isset_ is a sibling array
            // element, not this call's arg; keep LITERAL/CV wiring (#25188).
            return null;
        }
        if (
            $argIsDeadTemp
            && !$argIsProducerResult
            && !$this->issetOrEmptyProducerIsImmediateCallPrelude($producer, $cfgCallOp, $block)
        ) {
            // isset() && … as call arg — php-cfg dead temp is && merge, not hoisted Isset_ (#10704).
            return null;
        }
        if ($producer instanceof Op\Expr\Isset_ && 1 === count($producer->vars)) {
            $nullsafeChain = $this->collectNullsafePropertyFetchChain($producer->vars[0], $block);
            if ([] !== $nullsafeChain) {
                $existingSlot = $block->slotForOperand($producer->result);
                if (null !== $existingSlot) {
                    return $existingSlot;
                }
            }
        }
        if ($producer instanceof Op\Expr\Empty_) {
            $nullsafeChain = $this->collectNullsafePropertyFetchChainForEmpty($producer, $block);
            if ([] !== $nullsafeChain) {
                $existingSlot = $block->slotForOperand($producer->result);
                if (null !== $existingSlot) {
                    return $existingSlot;
                }
            }
        }
        if ($producer instanceof Op\Expr\Isset_ && !$this->issetExprLoweringEmitted($block, $producer)) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        if ($producer instanceof Op\Expr\Empty_ && !$this->emptyExprLoweringEmitted($block, $producer)) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
        }

        return $this->slotForEmittedIssetOrEmptyProducer($block, $producer)
            ?? $this->compileOperand($producer->result, $block, true);
    }

    /**
     * @return Op\Expr\Isset_|Op\Expr\Empty_|null
     */
    private function findHoistedIssetOrEmptyProducerForCallArg(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?Op\Expr {
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $callArgs = property_exists($cfgCallOp, 'args') && is_array($cfgCallOp->args) ? $cfgCallOp->args : [];
        if (\count($producers) === \count($callArgs) && isset($producers[$argIndex])) {
            $candidate = $producers[$argIndex];
            if ($candidate instanceof Op\Expr\Isset_ || $candidate instanceof Op\Expr\Empty_) {
                return $candidate;
            }
        }
        $matched = $this->matchInlineCallArgProducer($producers, $callArgs, $argIndex, $cfgCallOp);
        if ($matched instanceof Op\Expr\Isset_ || $matched instanceof Op\Expr\Empty_) {
            return $matched;
        }
        // var_dump(property_exists(...), isset(...)) — producers align 1:1; arg #0 is FuncCall,
        // not the trailing Isset_. Do not map hoisted[$argIndex] onto isset for earlier args (#15646).
        if (\count($producers) === \count($callArgs)) {
            return null;
        }
        $hoisted = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\Isset_ || $child instanceof Op\Expr\Empty_) {
                array_unshift($hoisted, $child);
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch) {
                continue;
            }
            break;
        }
        $producer = $hoisted[$argIndex] ?? null;

        return ($producer instanceof Op\Expr\Isset_ || $producer instanceof Op\Expr\Empty_) ? $producer : null;
    }

    /**
     * var_dump(isset(['a'=>1]['a'])) — php-cfg dead arg temp ≠ Isset_.result (#16462).
     */
    private function issetOrEmptyProducerIsImmediateCallPrelude(
        Op\Expr $producer,
        Op $cfgCallOp,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\Isset_ || $child instanceof Op\Expr\Empty_) {
                return $child === $producer;
            }
            if ($child instanceof Op\Expr\ArrayDimFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * Recover lowered isset()/empty() result slots when php-cfg dead arg temps omit dataflow (#11498).
     */
    private function slotForEmittedIssetOrEmptyProducer(Block $block, Op\Expr $producer): ?int
    {
        $slot = $block->slotForOperand($producer->result);
        if (null !== $slot) {
            return $slot;
        }
        if ($producer instanceof Op\Expr\Isset_) {
            for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
                $op = $block->opCodes[$i];
                if (OpCode::TYPE_ISSET === $op->type) {
                    return $op->arg1;
                }
            }
        }
        if ($producer instanceof Op\Expr\Empty_) {
            for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
                $op = $block->opCodes[$i];
                if (OpCode::TYPE_EMPTY === $op->type
                    || OpCode::TYPE_EMPTY_OBJECT_PROPERTY === $op->type
                    || OpCode::TYPE_EMPTY_STATIC_PROPERTY === $op->type
                    || OpCode::TYPE_EMPTY_DIMENSION === $op->type) {
                    return $op->arg1;
                }
            }
        }

        return null;
    }

    private function emptyExprLoweringEmitted(Block $block, Op\Expr\Empty_ $empty): bool
    {
        $slot = $block->slotForOperand($empty->result);
        if (null === $slot) {
            return false;
        }
        foreach ($block->opCodes as $op) {
            if ($op->arg1 !== $slot) {
                continue;
            }
            if (OpCode::TYPE_EMPTY === $op->type
                || OpCode::TYPE_EMPTY_OBJECT_PROPERTY === $op->type
                || OpCode::TYPE_EMPTY_STATIC_PROPERTY === $op->type
                || OpCode::TYPE_EMPTY_DIMENSION === $op->type) {
                return true;
            }
        }

        return false;
    }

    private function issetExprLoweringEmitted(Block $block, Op\Expr\Isset_ $expr): bool
    {
        $slot = $block->slotForOperand($expr->result);
        if (null === $slot) {
            return false;
        }
        foreach ($block->opCodes as $op) {
            if ($op->arg1 !== $slot) {
                continue;
            }
            if (OpCode::TYPE_ISSET === $op->type) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg may clear Empty_/BooleanNot->expr after SSA phi replaceWith; recover read operand (#6829).
     */
    private function unaryExprOperandForRead(Op\Expr $expr, Block $block): ?Operand
    {
        if (null !== $expr->expr) {
            return $expr->expr;
        }
        if ($expr instanceof Op\Expr\Empty_) {
            return $this->recoverEmptyExprOperand($expr, $block);
        }
        if ($expr instanceof Op\Expr\BooleanNot) {
            return $this->recoverBooleanNotExprOperand($expr, $block);
        }

        return null;
    }

    private function compileUnaryExprReadOperand(Op\Expr $expr, Block $block): ?int
    {
        $operand = $this->unaryExprOperandForRead($expr, $block);

        return null !== $operand ? $this->compileOperand($operand, $block, true) : null;
    }

    /**
     * BooleanNot.expr cleared while JumpIf still uses result — find negated operand (#6829).
     */
    private function recoverBooleanNotExprOperand(Op\Expr\BooleanNot $expr, Block $block): ?Operand
    {
        $func = $block->func;
        if (null === $func?->cfg) {
            return null;
        }
        $line = $expr->getLine();
        $nearest = null;
        $nearestLine = -1;
        $walk = function ($node) use (&$walk, $line, &$nearest, &$nearestLine): void {
            if ($node instanceof Op\Expr\Assign && $node->getLine() <= $line && $node->getLine() > $nearestLine) {
                $nearestLine = $node->getLine();
                $nearest = $node->var;
            }
            if ($node instanceof CfgBlock) {
                foreach ($node->children as $child) {
                    $walk($child);
                }
            }
            if ($node instanceof Op\Stmt\JumpIf) {
                $walk($node->if);
                $walk($node->else);
            }
        };
        $walk($func->cfg);

        return $nearest;
    }

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
            [$containerSlot, $dimSlot] = null !== $propFetch
                ? $this->resolveIssetTargetFromPropertyFetch($propFetch, $current)
                : (null !== $staticPropFetch
                    ? $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $current)
                    : (null !== $dimFetch
                        ? $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $current)
                        : $this->resolveIssetTarget($var, $current)));
            $checkSlot = $resultSlot;
            if ($i < $last) {
                $checkSlot = $this->compileBoolTemporary($current);
            }
            if (null === $containerSlot) {
                $varSlot = $this->compileOperand($var, $current, true);
                $current->addOpCode(new OpCode(OpCode::TYPE_ISSET, $checkSlot, $varSlot, null));
            } else {
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
