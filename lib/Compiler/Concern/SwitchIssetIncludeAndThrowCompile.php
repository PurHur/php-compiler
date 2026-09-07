<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\Web\ConstStringFolder;
use PHPCompiler\Web\IncludePathResolver;

/**
 * Switch / isset / include / throw compile helpers (#36387 / #36403).
 *
 * Extracted from {@see ErrorSuppressAndPropertyFetch} so gen-0 split-TU can
 * hollow a smaller Concern TU. Property-fetch read stays in the parent trait;
 * quiet dim / empty unset helpers live in {@see IssetEmptyUnsetAndDimFetchCompile}.
 *
 * Mirrors php-src Zend/zend_compile.c (zend_compile_switch, zend_compile_isset,
 * zend_compile_include, zend_compile_throw) — move-only; no behavior change.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait SwitchIssetIncludeAndThrowCompile
{
    /**
     * Lower CFG switch to JUMPIF/EQUAL chain (JIT-safe; TYPE_CASE branchIf needs bool #96).
     */
    protected function compileSwitchAsJumpIfChain(Op\Stmt\Switch_ $switch, Block $block): void
    {
        if (!isset($switch->cond)) {
            $this->throwCompileLogic('Switch missing condition operand');
        }
        $condSlot = $this->requireOperandSlot(
            $this->compileOperand($switch->cond, $block, true),
            'switch condition'
        );
        $caseCount = count($switch->cases);
        if (0 === $caseCount) {
            $defaultOp = new OpCode(OpCode::TYPE_JUMP);
            $defaultOp->block1 = $this->compileCfgBranch($switch->default, $block);
            $block->addOpCode($defaultOp);

            return;
        }

        $current = $block;
        $savedSwitchJumpIfChain = $this->compilingSwitchJumpIfChain;
        $this->compilingSwitchJumpIfChain = true;
        for ($i = 0; $i < $caseCount; ++$i) {
            $eqSlot = $this->requireOperandSlot(
                $this->compileBoolTemporary($current),
                'switch equality temporary'
            );
            $caseSlot = $this->requireOperandSlot(
                $this->compileSwitchCaseOperand($switch->cases[$i], $current),
                'switch case #'.$i
            );
            $current->addOpCode(new OpCode(
                OpCode::TYPE_EQUAL,
                $eqSlot,
                $condSlot,
                $caseSlot
            ));

            $caseTarget = $this->compileCfgBranch($switch->targets[$i], $block);
            $isLast = $i === $caseCount - 1;
            if ($isLast) {
                $elseTarget = $this->compileCfgBranch($switch->default, $block);
            } else {
                $elseTarget = new Block($block->orig);
                $elseTarget->syntheticCfgBranch = true;
                $elseTarget->inheritUndefinedLocals = true;
                $elseTarget->inheritScopeFrom($current);
                $this->inheritFuncFromParent($elseTarget, $block);
            }

            $jump = new OpCode(OpCode::TYPE_JUMPIF, $eqSlot);
            $jump->block1 = $caseTarget;
            $jump->block2 = $elseTarget;
            $current->addOpCode($jump);
            $caseTarget->parents[] = $current;
            $elseTarget->parents[] = $current;
            if (!$isLast) {
                $current = $elseTarget;
            }
        }
        $this->compilingSwitchJumpIfChain = $savedSwitchJumpIfChain;
    }

    /**
     * Materialize switch case labels at runtime — php-cfg Switch_ cases may lack preceding fetches (#8767).
     */
    protected function compileSwitchCaseOperand(Operand $caseOperand, Block $block): ?int
    {
        if (null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if (!$child instanceof Op\Expr\ClassConstFetch) {
                    continue;
                }
                if ($child->result !== $caseOperand && !$this->operandsReferToSameVariable($child->result, $caseOperand)) {
                    continue;
                }
                foreach ($this->compileClassConstFetch($child, $block) as $op) {
                    $block->addOpCode($op);
                }

                return $this->compileOperand($caseOperand, $block, true);
            }
        }

        return $this->compileOperand($caseOperand, $block, true);
    }

    private const ISSET_EXPRESSION_COMPILE_ERROR =
        'Cannot use isset() on the result of an expression (you can use "null !== expression" instead)';

    /** Empty `[]` offset in read context — Zend/zend_language_parser.y (#12303). */
    private const ARRAY_EMPTY_OFFSET_READ_COMPILE_ERROR = 'Cannot use [] for reading';

    /**
     * Zend zend_compile.c zend_is_variable(): isset() operands must be variables, dims, or properties (#8802).
     */
    protected function assertIssetVariableOperand(Operand $operand, Block $block): void
    {
        if (null !== $this->findCoalescePropertyFetch($operand, $block)) {
            return;
        }
        if (null !== $this->findCoalesceStaticPropertyFetch($operand, $block)) {
            return;
        }
        if (null !== $this->findCoalesceArrayDimFetch($operand, $block)) {
            return;
        }
        if (null !== $this->unwrapVariableOperand($operand)) {
            return;
        }
        if (null !== $this->unwrapStaticPropertyFetch($operand)) {
            return;
        }

        $this->throwCompileError(self::ISSET_EXPRESSION_COMPILE_ERROR);
    }

    /**
     * @return OpCode[]
     */
    protected function compileIsset(Op\Expr\Isset_ $expr, Block $block): array
    {
        assert(1 === count($expr->vars));
        $nullsafeChain = $this->collectNullsafePropertyFetchChain($expr->vars[0], $block);
        if ([] !== $nullsafeChain) {
            $this->compileIssetNullsafePropertyFetchChain($nullsafeChain, $expr, $block);

            return [];
        }
        $this->assertIssetVariableOperand($expr->vars[0], $block);
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $propFetch = $this->findCoalescePropertyFetch($expr->vars[0], $block);
        $staticPropFetch = null !== $propFetch
            ? null
            : $this->findCoalesceStaticPropertyFetch($expr->vars[0], $block);
        $dimFetch = null !== $propFetch || null !== $staticPropFetch
            ? null
            : $this->findCoalesceArrayDimFetch($expr->vars[0], $block);
        if (null !== $dimFetch) {
            $chain = $this->collectArrayDimFetchChain($dimFetch, $block);
            foreach ($chain as $chainFetch) {
                $this->rejectArrayEmptyOffsetRead($chainFetch, $block);
            }
            [$prefixOps, $containerSlot] = $this->emitQuietDimFetchChainPrefix($chain, $block);
            $lastFetch = $chain[count($chain) - 1];
            $dimSlot = null !== $lastFetch->dim
                ? $this->compileOperand($lastFetch->dim, $block, true)
                : null;
            $issetOp = $this->makeIssetOpCode($resultSlot, $containerSlot, $dimSlot, false);
            $prefixOps[] = $issetOp;

            return $prefixOps;
        }
        [$containerSlot, $dimSlot] = null !== $propFetch
            ? $this->resolveIssetTargetFromPropertyFetch($propFetch, $block)
            : (null !== $staticPropFetch
                ? $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $block)
                : $this->resolveIssetTarget($expr->vars[0], $block));
        if (null === $containerSlot) {
            $varSlot = $this->compileOperand($expr->vars[0], $block, true);

            return [new OpCode(OpCode::TYPE_ISSET, $resultSlot, $varSlot, null)];
        }

        $issetOp = $this->makeIssetOpCode($resultSlot, $containerSlot, $dimSlot, null !== $propFetch);
        if (null !== $staticPropFetch) {
            $issetOp->issetOnStaticProperty = true;
        }

        return [$issetOp];
    }

    protected function compileIncludeOp(Op\Expr\Include_ $expr, Block $block): OpCode
    {
        // Include expression value is independent of the enclosing function return type
        // (void/never blocks must still materialize require/include results for call args) (#21938).
        $resultSlot = null;
        if (isset($expr->result) && $this->includeNeedsReturnSlot($expr->result, $block)) {
            $resultSlot = $this->compileOperand($expr->result, $block, false);
        }

        $sourceFile = $expr->getFile() ?? '';
        $includeKind = match ($expr->type) {
            Op\Expr\Include_::TYPE_INCLUDE => OpCode::INCLUDE_KIND_INCLUDE,
            Op\Expr\Include_::TYPE_INCLUDE_ONCE => OpCode::INCLUDE_KIND_INCLUDE_ONCE,
            Op\Expr\Include_::TYPE_REQUIRE => OpCode::INCLUDE_KIND_REQUIRE,
            Op\Expr\Include_::TYPE_REQUIRE_ONCE => OpCode::INCLUDE_KIND_REQUIRE_ONCE,
            default => OpCode::INCLUDE_KIND_INCLUDE_ONCE,
        };

        $deploySpec = ConstStringFolder::tryParseDeployInclude($block->orig, $expr->expr, $sourceFile);
        if (null !== $deploySpec) {
            $pathIndex = count($block->deployIncludePaths);
            $block->deployIncludePaths[$pathIndex] = $deploySpec;
            $compilePath = $deploySpec['compile'] ?? '';
            $pathOperand = new Operand\Literal('' !== $compilePath ? $compilePath : ' ');
            $pathOperand->type = Type::string();

            $op = new OpCode(
                OpCode::TYPE_INCLUDE,
                $this->compileOperand($pathOperand, $block, true),
                $resultSlot,
                $pathIndex,
            );
            $op->includeKind = $includeKind;
            $block->emittedIncludeOrEvalExprIds[spl_object_id($expr)] = true;

            return $op;
        }

        $includePath = ConstStringFolder::foldForInclude($block->orig, $expr->expr, $sourceFile);
        if (null !== $includePath) {
            $resolved = IncludePathResolver::resolve($includePath, $expr->getFile());
            if (null !== $resolved) {
                $this->markCallerLocalsUsedByLiteralInclude($resolved, $block);
                $literal = new Operand\Literal($resolved);
                $literal->type = Type::string();
                $pathIndex = count($block->literalIncludePaths);
                $block->literalIncludePaths[$pathIndex] = $resolved;

                $op = new OpCode(
                    OpCode::TYPE_INCLUDE,
                    $this->compileOperand($literal, $block, true),
                    $resultSlot,
                    $pathIndex,
                );
                $op->includeKind = $includeKind;
                $block->emittedIncludeOrEvalExprIds[spl_object_id($expr)] = true;

                return $op;
            }
        }

        $op = new OpCode(
            OpCode::TYPE_INCLUDE,
            $this->compileOperand($expr->expr, $block, true),
            $resultSlot,
        );
        $op->includeKind = $includeKind;
        $block->emittedIncludeOrEvalExprIds[spl_object_id($expr)] = true;

        return $op;
    }

    /**
     * php-cfg emits inner expr ops (New_, …) before Throw_; lower them inside compileExpr(Throw_) (#3802).
     *
     * @param Op[] $ops
     */
    private function isLoweredByFollowingThrow(Op $op, array $ops, int $index): bool
    {
        if (!$op instanceof Op\Expr) {
            return false;
        }
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\Throw_) {
                return $this->exprOpFeedsThrowOperand($op, $next);
            }
            if (!$next instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

    private function exprOpFeedsThrowOperand(Op\Expr $op, Op\Expr\Throw_ $throw): bool
    {
        return $this->operandsChainEqual($op->result, $throw->expr);
    }

    /**
     * Ops after throw-expr in the same CFG block are unreachable (?: arm, &&/|| RHS, = throw …) (#3802).
     *
     * @param Op[] $ops
     */
    private function isUnreachableAfterThrow(Op $op, array $ops, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; --$j) {
            if ($ops[$j] instanceof Op\Expr\BinaryOp\Coalesce) {
                // ?? RHS throw is lowered on the coalesce branch; following stmts stay reachable (#9447).
                return false;
            }
            if ($ops[$j] instanceof Op\Expr\Throw_) {
                return true;
            }
            if (!$ops[$j] instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

    /**
     * php-cfg emits `Throw_` then `Isset_(throw.result)` for `isset(throw …)`.
     * Without a look-ahead, isUnreachableAfterThrow skips Isset_ and the throw runs (#29086).
     *
     * @param Op[] $ops
     */
    private function throwResultFeedsFollowingIsset(Op\Expr\Throw_ $throw, array $ops, int $index): bool
    {
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\Isset_) {
                foreach ($next->vars as $var) {
                    if ($this->operandsChainEqual($throw->result, $var)) {
                        return true;
                    }
                }

                return false;
            }
            if (!$next instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

    private function findThrowInnerExprOp(Op\Expr\Throw_ $throw, Block $block): ?Op\Expr
    {
        $root = $this->unwrapOperandChain($throw->expr);
        if ($root instanceof Op\Expr) {
            return $root;
        }

        return $this->findOrigExprOpForOperand($throw->expr, $block);
    }

    /**
     * @return list<OpCode>
     */
    private function compileThrowExpression(Op\Expr\Throw_ $expr, Block $block, Block ...$extraSearchBlocks): array
    {
        if ($this->isBareRethrowExpression($expr, $block, ...$extraSearchBlocks)) {
            return [new OpCode(OpCode::TYPE_RETHROW)];
        }

        $newOp = $this->findNewExprForThrowOperand($expr, $block, ...$extraSearchBlocks);
        $ops = [];
        $throwSlot = null;
        $throwEmitBlock = null;
        if (null !== $newOp) {
            foreach ($this->compileNewExprForThrow($newOp, $block) as $innerOpcode) {
                $ops[] = $innerOpcode;
            }
            $throwSlot = $this->compileOperand($newOp->result, $block, true);
        } else {
            $innerOp = $this->findThrowInnerExprOp($expr, $block);
            if (null !== $innerOp) {
                if ($innerOp instanceof Op\Expr\BinaryOp\Coalesce) {
                    // ?? merge must complete before TYPE_THROW; compileExpr(Coalesce) leaves throw on entry block (#15315).
                    $throwEmitBlock = $this->compileCoalesce($innerOp, $block);
                } else {
                    foreach ($this->compileExpr($innerOp, $block) as $innerOpcode) {
                        $ops[] = $innerOpcode;
                    }
                }
            }
        }
        $slotBlock = $throwEmitBlock ?? $block;
        if (null === $throwSlot) {
            $throwSlot = $this->compileOperand($expr->expr, $slotBlock, true);
        }
        $line = $expr->getLine();
        $throwOp = new OpCode(
            OpCode::TYPE_THROW,
            $throwSlot,
            $line > 0 ? $line : null
        );
        if (null !== $throwEmitBlock) {
            $throwEmitBlock->addOpCode($throwOp);

            return [];
        }
        $ops[] = $throwOp;

        return $ops;
    }

    private function findNewExprForThrowOperand(Op\Expr\Throw_ $throw, Block ...$searchBlocks): ?Op\Expr\New_
    {
        foreach ($searchBlocks as $searchBlock) {
            if (null === $searchBlock->orig) {
                continue;
            }
            foreach ($searchBlock->orig->children as $child) {
                if ($child instanceof Op\Expr\New_ && $this->operandsChainEqual($child->result, $throw->expr)) {
                    return $child;
                }
            }
        }

        return null;
    }

    /**
     * @return list<OpCode>
     */
    private function compileNewExprForThrow(Op\Expr\New_ $expr, Block $block): array
    {
        $this->rejectPseudoClassNewOutsideClassScope($expr, $block);
        // Same as Op\Expr\New_:: class path — defer abstract/enum instantiate to runtime (#25787).
        $className = $this->literalScopeClassName($expr->class);
        $resultSlot = $block->forceFreshVarSlot($expr->result);
        $mergeEcho = $this->mergeEchoSlotForBranch($block);
        if (null !== $mergeEcho && $resultSlot === $mergeEcho) {
            $resultSlot = $block->forceFreshVarSlot($expr->result);
        }
        $line = $expr->getLine();
        $return = [
            new OpCode(
                OpCode::TYPE_NEW,
                $resultSlot,
                $this->compileOperand($expr->class, $block, true),
                $line > 0 ? $line : null
            ),
        ];
        foreach ($this->compileCallArgSends($expr->args, $block, $className, $expr) as $send) {
            $return[] = $send;
        }
        $return[] = $this->compileFuncCallExecOpcode(
            $expr->result,
            $block,
            $line > 0 ? $line : 0
        );

        return $return;
    }

    private function compileOrigExprForOperand(Operand $operand, Block $block): void
    {
        $exprOp = $this->findOrigExprOpForOperand($operand, $block);
        if (null === $exprOp) {
            return;
        }
        $this->compileDeferredCoalesceBranchExpr($exprOp, $block);
    }

    private function findOrigExprOpForOperand(Operand $operand, Block $block): ?Op\Expr
    {
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr) {
            return $root;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr && $this->operandsChainEqual($child->result, $operand)) {
                return $child;
            }
        }

        return null;
    }
}
