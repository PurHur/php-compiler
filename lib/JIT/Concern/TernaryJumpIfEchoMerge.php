<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Ternary / JUMPIF echo-merge, ?: return-phi, and arm-tail CFG RETURN helpers
 * (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\JIT} so the hub keeps shrinking toward
 * the 20k size-budget target (Concern trait; same namespace as parent).
 * Includes {@see emitCfgReturnOperand} (#8555) left in the hub by #36751.
 */
trait TernaryJumpIfEchoMerge
{
    /**
     * php-cfg dead operands before branchIf run before any successor; skip inside inlined
     * includes so template locals survive layout title-branch partial includes (#784, #764).
     */
    private function shouldFreeDeadVariablesBeforeBranch(): bool
    {
        return 0 === $this->context->inlineIncludeDepth;
    }

    /**
     * List-unpack merge that inlines an include still needs assign-block locals (#846).
     * String-key CFG splits ({@see Compiler::splitCfgBlockAfterStringKeyedArray}) set
     * {@see Block::$inheritUndefinedLocals} so unnamed temps (e.g. FETCH_STATIC_PROP_R
     * copies) stay live across the jump — freeDeadVariables before the branch would
     * delref them and bare `echo C::$a["k"]` reads empty (#33936 / #23354).
     */
    private function mergeBlockInheritsCallerLocals(?Block $mergeBlock): bool
    {
        if (null === $mergeBlock) {
            return false;
        }
        if ($mergeBlock->inheritUndefinedLocals) {
            return true;
        }
        foreach ($mergeBlock->opCodes as $op) {
            if (OpCode::TYPE_INCLUDE === $op->type) {
                return true;
            }
        }

        return false;
    }

    private function branchJumpMergeBlock(?Block $branch): ?Block
    {
        if (null === $branch) {
            return null;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_JUMP === $branchOp->type) {
                return $branchOp->block1;
            }
        }

        return null;
    }

    /** Both ?: arms jump to a merge block ending in RETURN (#4280, #8555). */
    private function jumpIfTargetsReturnMerge(?Block $ifBlock, ?Block $elseBlock): bool
    {
        $ifMerge = $this->branchJumpMergeBlock($ifBlock);
        $elseMerge = $this->branchJumpMergeBlock($elseBlock);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        // `$c + ($cond ? a : b)` merges into PLUS then RETURN — not a pure ?: return.
        // Early-returning arm assigns would skip the PLUS and drop the carry (#33719).
        if (!$this->mergeBlockIsPureTernaryReturn($ifMerge)) {
            return false;
        }
        $phi = $this->ternaryReturnPhiOperand($ifMerge);
        if (null === $phi) {
            return false;
        }
        if (!$this->branchIsTernaryReturnMergeArm($ifBlock) && !$this->branchIsTernaryReturnMergeArm($elseBlock)) {
            return false;
        }
        // Switch-as-JUMPIF chains share a post-switch merge RETURN but do not assign into
        // the ?: phi before breaking; require an arm assign (#878).
        return null !== $this->ternaryPhiAssignSourceOperand($ifBlock, $ifMerge)
            || null !== $this->ternaryPhiAssignSourceOperand($elseBlock, $ifMerge);
    }

    /**
     * True when the ?: merge is only a RETURN of the phi (no PLUS/CONCAT/ASSIGN first).
     *
     * `$c + ($v === null ? 10 : $v)` has PLUS before RETURN — arms must fall through (#33719).
     */
    private function mergeBlockIsPureTernaryReturn(Block $mergeBlock): bool
    {
        $sawReturn = false;
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (OpCode::TYPE_RETURN === $mergeOp->type || OpCode::TYPE_RETURN_VOID === $mergeOp->type) {
                $sawReturn = true;
                break;
            }
            // Any other opcode means the ternary feeds an expression, not a bare return.
            return false;
        }

        return $sawReturn;
    }

    /**
     * Both ?: arms jump to a merge that consumes the phi alias via ECHO or CONCAT (#3790, #18052, #32908).
     *
     * `($o ? $o->nodeName : 'null') . '!'` remaps the merge use to the fetch alias while ECHO sees
     * only the CONCAT result — {@see mergeTernaryResultSlot} must win over {@see mergeEchoSlot}.
     */
    private function jumpIfTargetsEchoMerge(?Block $ifBlock, ?Block $elseBlock, ?Block $jumpIfBlock = null, int $jumpIfIndex = -1): bool
    {
        $ifMerge = $this->branchJumpMergeBlock($ifBlock);
        $elseMerge = $this->branchJumpMergeBlock($elseBlock);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        if (null === $this->mergeTernaryResultSlot($ifMerge, $ifBlock, $elseBlock)) {
            return false;
        }
        if (null !== $this->ternaryReturnPhiOperand($ifMerge)) {
            return false;
        }
        if (
            !$this->ternaryEchoMergeNeedsStackPhi($ifMerge, $ifBlock, $elseBlock)
            && !$this->ternaryEchoMergeNeedsLiteralArmRedirect($jumpIfBlock, $jumpIfIndex, $ifMerge, $ifBlock, $elseBlock)
        ) {
            return false;
        }

        return null !== $this->ternaryEchoPhiOperand($ifMerge, $ifBlock, $elseBlock);
    }

    /**
     * JUMPIF condition → i1. Script-global string boxes keep compileTimeString after
     * assign; use zend_is_true(IS_STRING) rules instead of boxedTruthyScalar (#32919).
     * php-src: Zend/zend_operators.c zend_is_true / convert_to_boolean IS_STRING.
     */
    private function jitJumpIfConditionToBool(Variable $condVar): \PHPLLVM\Value
    {
        if (null !== $condVar->compileTimeString) {
            return $this->context->constantFromBool(
                self::phpStringIsTruthy($condVar->compileTimeString)
            );
        }
        if (Variable::TYPE_STRING === $condVar->type) {
            return \PHPCompiler\ext\standard\boolval::stringTruthy(
                $this->context,
                $this->context->helper->loadValue($condVar)
            );
        }

        return $this->context->castToBool($this->context->helper->loadValue($condVar));
    }

    /** zend_is_true for IS_STRING: non-empty and not "0". */
    private static function phpStringIsTruthy(string $s): bool
    {
        return '' !== $s && '0' !== $s;
    }

    /**
     * Match `echo match(...)` — JUMPIF chain where arms assign into a shared merge ECHO slot (#24143).
     *
     * php-cfg seeds the result with NULL then fans out IDENTICAL+JUMPIF arms; the else of the outer
     * JUMPIF is another JUMPIF, so {@see jumpIfTargetsEchoMerge} never fires. Thin AOT then echoes
     * an uninitialized / type-confused phi and segfaults after c:main_before_php (JIT is fine).
     */
    private function jumpIfTargetsMatchEchoMerge(?Block $ifBlock, ?Block $elseBlock): bool
    {
        return null !== $this->matchEchoMergeBlock($ifBlock, $elseBlock);
    }

    private function matchEchoMergeBlock(?Block $ifBlock, ?Block $elseBlock): ?Block
    {
        if (null === $ifBlock || null === $elseBlock) {
            return null;
        }
        $ifMerge = $this->branchJumpMergeBlock($ifBlock);
        if (null === $ifMerge) {
            return null;
        }
        $echoSlot = $this->mergeEchoSlot($ifMerge);
        if (null === $echoSlot || null !== $this->ternaryReturnPhiOperand($ifMerge)) {
            return null;
        }
        if (!$this->blockAssignsToEchoSlot($ifBlock, $echoSlot)) {
            return null;
        }
        $elseMerge = $this->branchJumpMergeBlock($elseBlock);
        if ($elseMerge === $ifMerge && $this->blockAssignsToEchoSlot($elseBlock, $echoSlot)) {
            return $ifMerge;
        }

        return $this->matchJumpIfChainReachesEchoMerge($elseBlock, $ifMerge, $echoSlot)
            ? $ifMerge
            : null;
    }

    private function matchJumpIfChainReachesEchoMerge(Block $block, Block $merge, int $echoSlot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_JUMPIF !== $op->type) {
                continue;
            }

            return $this->matchArmOrChainReachesEchoMerge($op->block1, $merge, $echoSlot)
                && $this->matchArmOrChainReachesEchoMerge($op->block2, $merge, $echoSlot);
        }

        return false;
    }

    private function matchArmOrChainReachesEchoMerge(?Block $block, Block $merge, int $echoSlot): bool
    {
        if (null === $block) {
            return false;
        }
        if ($this->branchJumpMergeBlock($block) === $merge && $this->blockAssignsToEchoSlot($block, $echoSlot)) {
            return true;
        }

        return $this->matchJumpIfChainReachesEchoMerge($block, $merge, $echoSlot);
    }

    private function blockAssignsToEchoSlot(Block $block, int $echoSlot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type && null !== $op->arg2 && (int) $op->arg2 === $echoSlot) {
                return true;
            }
        }

        return false;
    }

    /** Shared match/?: merge ECHO operand (alias temp), not the per-arm ASSIGN result. */
    private function mergeEchoOperand(Block $mergeBlock): ?Operand
    {
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (OpCode::TYPE_ECHO === $mergeOp->type && null !== $mergeOp->arg1) {
                return $mergeBlock->getOperand($mergeOp->arg1);
            }
        }

        return null;
    }

    /**
     * Literal ?: echo arms after side effects in the JUMPIF block need merge-block redirect (#18784, #23915).
     *
     * Pure literal ternaries with nothing before the JUMPIF keep the default assign path; enabling
     * echo merge there mis-lowers and can crash AOT init (#18052). A preceding ECHO (comma-echo
     * `echo A, Cond ? X : Y`) or object-producing call pollutes the default phi path — redirect.
     */
    private function ternaryEchoMergeNeedsLiteralArmRedirect(
        ?Block $jumpIfBlock,
        int $jumpIfIndex,
        Block $mergeBlock,
        ?Block $ifBlock,
        ?Block $elseBlock
    ): bool {
        if (null === $jumpIfBlock || $jumpIfIndex < 0 || !$this->ternaryEchoMergeHasLiteralArmsOnly($mergeBlock, $ifBlock, $elseBlock)) {
            return false;
        }

        return $this->ternaryEchoMergeFollowsSlotPollutingOp($jumpIfBlock, $jumpIfIndex);
    }

    /** @return bool true when every ?: arm assigns a literal into the merge ternary-result slot */
    private function ternaryEchoMergeHasLiteralArmsOnly(Block $mergeBlock, ?Block $ifBlock, ?Block $elseBlock): bool
    {
        $resultSlot = $this->mergeTernaryResultSlot($mergeBlock, $ifBlock, $elseBlock);
        if (null === $resultSlot) {
            return false;
        }
        $literalArmCount = 0;
        foreach ([$ifBlock, $elseBlock] as $branch) {
            if (null === $branch) {
                continue;
            }
            foreach ($branch->opCodes as $branchOp) {
                if (OpCode::TYPE_ASSIGN !== $branchOp->type || (int) $branchOp->arg2 !== $resultSlot) {
                    continue;
                }
                $rhsSlot = null !== $branchOp->arg3 ? (int) $branchOp->arg3 : (int) $branchOp->arg2;
                $rhs = $branch->getOperand($rhsSlot);
                if (!$rhs instanceof Operand\Literal) {
                    return false;
                }
                ++$literalArmCount;
            }
        }

        return $literalArmCount > 0;
    }

    /**
     * True when a prior op in the JUMPIF block may pollute the ?: echo slot (#18784, #19459, #23915).
     *
     * Calls/methods leave object temps on the alias; a leading comma-echo ECHO leaves the merge
     * ECHO reading an empty/uninitialized phi under AOT (#23915).
     */
    private function ternaryEchoMergeFollowsSlotPollutingOp(Block $jumpIfBlock, int $jumpIfIndex): bool
    {
        for ($i = 0; $i < $jumpIfIndex; ++$i) {
            $prior = $jumpIfBlock->opCodes[$i];
            if (
                OpCode::TYPE_METHODCALL_INIT === $prior->type
                || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $prior->type
                || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $prior->type
                || OpCode::TYPE_ECHO === $prior->type
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when merge CONCAT reads the ?: result (directly or via ASSIGN into $b) (#35095).
     *
     * Literal-arm echo redirect (#18784) only rewrites ECHO — merge CONCAT still runs and
     * reads an uninitialized SSA phi when stack-phi was skipped for literal arms, dropping
     * the LHS or SIGSEGVing (re-#14142 / #33094).
     */
    private function mergeConcatReadsTernaryResult(Block $mergeBlock, int $resultSlot): bool
    {
        $aliases = [$resultSlot => true];
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (
                OpCode::TYPE_ASSIGN === $mergeOp->type
                && null !== $mergeOp->arg2
                && null !== $mergeOp->arg3
            ) {
                $src = (int) $mergeOp->arg3;
                $dest = (int) $mergeOp->arg2;
                if (isset($aliases[$src])) {
                    $aliases[$dest] = true;
                }
            }
            if (
                OpCode::TYPE_CONCAT === $mergeOp->type
                && null !== $mergeOp->arg2
                && null !== $mergeOp->arg3
                && (
                    isset($aliases[(int) $mergeOp->arg2])
                    || isset($aliases[(int) $mergeOp->arg3])
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /** Literal ?: echo arms keep operand redirect; non-literal arms need stack-slot phi (#18052). */
    private function ternaryEchoMergeNeedsStackPhi(Block $mergeBlock, ?Block $ifBlock, ?Block $elseBlock): bool
    {
        $resultSlot = $this->mergeTernaryResultSlot($mergeBlock, $ifBlock, $elseBlock);
        if (null === $resultSlot) {
            return false;
        }
        // Merge CONCAT(lit, ?:phi) / `$b = ?:; echo lit.$b` needs stack phi even when both
        // arms assign string literals (#35095).
        if ($this->mergeConcatReadsTernaryResult($mergeBlock, $resultSlot)) {
            return true;
        }
        foreach ([$ifBlock, $elseBlock] as $branch) {
            if (null === $branch) {
                continue;
            }
            foreach ($branch->opCodes as $branchOp) {
                // php-cfg often folds `($a.'x')` into CONCAT whose dest *is* the echo phi
                // slot (no ASSIGN). That still needs a stack phi — otherwise CONCAT allocates
                // an ephemeral string while merge ECHO reads a null-initialized sibling (#33849).
                if (
                    OpCode::TYPE_CONCAT === $branchOp->type
                    && null !== $branchOp->arg1
                    && (int) $branchOp->arg1 === $resultSlot
                ) {
                    return true;
                }
                if (OpCode::TYPE_ASSIGN !== $branchOp->type || (int) $branchOp->arg2 !== $resultSlot) {
                    continue;
                }
                $rhsSlot = null !== $branchOp->arg3 ? (int) $branchOp->arg3 : (int) $branchOp->arg2;
                $rhs = $branch->getOperand($rhsSlot);
                if (!$rhs instanceof Operand\Literal) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Literal assigned into the merge ternary-result slot on a ?: arm (#18784). */
    private function ternaryEchoBranchLiteralString(?Block $branch, Block $mergeBlock): ?string
    {
        // Single-arm probe: pass the branch as both sides so ASSIGN.arg2 matching still works.
        $resultSlot = $this->mergeTernaryResultSlot($mergeBlock, $branch, $branch);
        if (null === $resultSlot || null === $branch) {
            return null;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_ASSIGN !== $branchOp->type || (int) $branchOp->arg2 !== $resultSlot) {
                continue;
            }
            $rhsSlot = null !== $branchOp->arg3 ? (int) $branchOp->arg3 : (int) $branchOp->arg2;
            $rhs = $branch->getOperand($rhsSlot);
            if ($rhs instanceof Operand\Literal && is_string($rhs->value)) {
                return $rhs->value;
            }

            return null;
        }

        return null;
    }

    /**
     * Echo compile-time ?: arm literals from the saved condition (#18784).
     *
     * Avoids merge-block ValueEcho reading a polluted alias temp in standalone AOT.
     *
     * Load the condition in the same open BB as {@see branchIf}: after ArrayObject
     * (and similar) construct the CFG {@see $continueBlock} may already be sealed while
     * the builder insert has moved on — positioning there then branching caused
     * "Terminator mid-block" / domination failures (#33094).
     */
    private function emitTernaryLiteralEchoMerge(
        \PHPLLVM\Value $conditionSlot,
        string $ifLiteral,
        string $elseLiteral,
        \PHPLLVM\BasicBlock $continueBlock
    ): \PHPLLVM\BasicBlock {
        static $seq = 0;
        $tag = '18784_'.(string) ++$seq;
        $ifBlock = JIT\BasicBlockHelper::append($this->context, 'ternary_echo_lit_if_'.$tag);
        $elseBlock = JIT\BasicBlockHelper::append($this->context, 'ternary_echo_lit_else_'.$tag);
        $doneBlock = JIT\BasicBlockHelper::append($this->context, 'ternary_echo_lit_done_'.$tag);
        $builder = $this->context->builder;
        // Prefer the live insert BB when open: ArrayObject::__construct (etc.) can seal the
        // CFG-mapped $continueBlock while later opcodes keep emitting on a successor (#33094).
        $insert = JIT\BasicBlockHelper::tryGetInsertBlock($this->context);
        if (null !== $insert && null === $insert->getTerminator()) {
            // already positioned
        } elseif (null === $continueBlock->getTerminator()) {
            $builder->positionAtEnd($continueBlock);
        } else {
            JIT\BasicBlockHelper::ensureOpenInsertBlock(
                $this->context,
                'ternary_echo_lit_from_'.$tag
            );
        }
        $condition = $builder->load($conditionSlot);
        $builder->branchIf($condition, $ifBlock, $elseBlock);
        $builder->positionAtEnd($ifBlock);
        JIT\ValueEchoHelper::echoLiteral($this->context, $ifLiteral);
        $builder->branch($doneBlock);
        $builder->positionAtEnd($elseBlock);
        JIT\ValueEchoHelper::echoLiteral($this->context, $elseLiteral);
        $builder->branch($doneBlock);
        $builder->positionAtEnd($doneBlock);

        return $doneBlock;
    }

    private function clearTernaryEchoLiteralMergeState(): void
    {
        $this->context->ternaryEchoLiteralConditionSlot = null;
        $this->context->ternaryEchoLiteralIf = null;
        $this->context->ternaryEchoLiteralElse = null;
    }

    /**
     * Literal affixes when merge ECHO is CONCAT(ternary, lit) or CONCAT(lit, ternary) (#33094 / #32908).
     *
     * @return array{0: string, 1: string} prefix, suffix
     */
    private function ternaryEchoConcatLiteralAffixes(
        Block $mergeBlock,
        ?Block $ifBlock,
        ?Block $elseBlock
    ): array {
        $ternarySlot = $this->mergeTernaryResultSlot($mergeBlock, $ifBlock, $elseBlock);
        if (null === $ternarySlot) {
            return ['', ''];
        }
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (OpCode::TYPE_CONCAT !== $mergeOp->type || null === $mergeOp->arg2 || null === $mergeOp->arg3) {
                continue;
            }
            $leftSlot = (int) $mergeOp->arg2;
            $rightSlot = (int) $mergeOp->arg3;
            $leftOp = $mergeBlock->getOperand($leftSlot);
            $rightOp = $mergeBlock->getOperand($rightSlot);
            if ($leftSlot === $ternarySlot && $rightOp instanceof Operand\Literal && is_string($rightOp->value)) {
                return ['', $rightOp->value];
            }
            if ($rightSlot === $ternarySlot && $leftOp instanceof Operand\Literal && is_string($leftOp->value)) {
                return [$leftOp->value, ''];
            }
        }

        return ['', ''];
    }

    private function mergeEchoSlot(Block $mergeBlock): ?int
    {
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (OpCode::TYPE_ECHO === $mergeOp->type) {
                return (int) $mergeOp->arg1;
            }
        }

        return null;
    }

    /**
     * Merge-block slot the ?: arms assign into — ECHO arg, CONCAT operand before ECHO (#32908),
     * or FUNCCALL ARG_SEND of the phi (#34944).
     *
     * For `echo ($o ? $o->prop : 'x') . '!'` php-cfg emits CONCAT(alias, lit) then ECHO(concat).
     * {@see mergeEchoSlot} alone returns the concat result, so arm ASSIGN.arg2 never matches and
     * stack-phi is skipped — AOT then concat-coerces a dead fetch temp to an empty string.
     *
     * Chained `tern1 . '|' . tern2` emits CONCAT(prior, tern2): both sides are non-literal; prefer
     * the side the arms actually ASSIGN into (usually the right).
     *
     * `var_export($o ? [$o->x] : null)` merges into ARG_SEND of the phi with no ECHO of that
     * slot — without recognizing ARG_SEND here, stack-phi never arms and AOT sends NULL (#34944).
     * php-src: Zend/zend_ast.c ZEND_AST_CONDITIONAL.
     *
     * `$x = $cond ? [$o->a] : [9]; var_export($x)` merges as ASSIGN($x, armPhi) then
     * ARG_SEND($x). Arms write armPhi, not $x — return armPhi so stack-phi arms (#34970).
     */
    private function mergeTernaryResultSlot(
        Block $mergeBlock,
        ?Block $ifBlock = null,
        ?Block $elseBlock = null
    ): ?int {
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (OpCode::TYPE_CONCAT === $mergeOp->type && null !== $mergeOp->arg2 && null !== $mergeOp->arg3) {
                $leftSlot = (int) $mergeOp->arg2;
                $rightSlot = (int) $mergeOp->arg3;
                $leftLit = $mergeBlock->getOperand($leftSlot) instanceof Operand\Literal;
                $rightLit = $mergeBlock->getOperand($rightSlot) instanceof Operand\Literal;
                if ($leftLit && !$rightLit) {
                    return $rightSlot;
                }
                if ($rightLit && !$leftLit) {
                    return $leftSlot;
                }
                foreach ([$rightSlot, $leftSlot] as $cand) {
                    if ($this->ternaryArmsAssignIntoSlot($ifBlock, $elseBlock, $cand)) {
                        return $cand;
                    }
                }

                return $leftSlot;
            }
            if (OpCode::TYPE_ECHO === $mergeOp->type && null !== $mergeOp->arg1) {
                return (int) $mergeOp->arg1;
            }
            // Merge copies arm phi into a named local before FUNCCALL/ECHO (#34970).
            if (
                OpCode::TYPE_ASSIGN === $mergeOp->type
                && null !== $mergeOp->arg2
                && null !== $mergeOp->arg3
                && (int) $mergeOp->arg1 !== (int) $mergeOp->arg2
            ) {
                $srcSlot = (int) $mergeOp->arg3;
                if ($this->ternaryArmsAssignIntoSlot($ifBlock, $elseBlock, $srcSlot)) {
                    return $srcSlot;
                }
                $destSlot = (int) $mergeOp->arg2;
                if ($this->ternaryArmsAssignIntoSlot($ifBlock, $elseBlock, $destSlot)) {
                    return $destSlot;
                }
            }
            // FUNCCALL merge: ARG_SEND of the ?: phi (Compiler remaps dead call-arg temps).
            if (
                OpCode::TYPE_ARG_SEND === $mergeOp->type
                && null !== $mergeOp->arg1
                && $this->ternaryArmsAssignIntoSlot($ifBlock, $elseBlock, (int) $mergeOp->arg1)
            ) {
                return (int) $mergeOp->arg1;
            }
        }

        return null;
    }

    /**
     * INIT_ARRAY(temp); ADD_ARRAY_ELEMENT(temp,…)*; ASSIGN(_, phi, temp) — multi-element
     * ?: true-arm when a later PROPERTY_FETCH reused the phi slot (#34970 / #34944).
     */
    private function initArrayCoalescePhiAfterElementTrail(
        Block $block,
        int $initIndex,
        int $initDestSlot
    ): ?int {
        $n = $block->nOpCodes;
        $j = $initIndex + 1;
        while ($j < $n) {
            $trail = $block->opCodes[$j];
            if (
                OpCode::TYPE_ADD_ARRAY_ELEMENT === $trail->type
                && null !== $trail->arg1
                && (int) $trail->arg1 === $initDestSlot
            ) {
                ++$j;
                continue;
            }
            break;
        }
        if ($j >= $n) {
            return null;
        }
        $assign = $block->opCodes[$j];
        if (
            OpCode::TYPE_ASSIGN !== $assign->type
            || null === $assign->arg2
            || null === $assign->arg3
            || (int) $assign->arg1 === (int) $assign->arg2
            || (int) $assign->arg3 !== $initDestSlot
        ) {
            return null;
        }
        $phiSlot = (int) $assign->arg2;
        if (!isset($this->context->coalesceMergeSlotOperands[$phiSlot])) {
            return null;
        }

        return $phiSlot;
    }

    /** True when a ?: arm ASSIGN.arg2 targets $slot (php-cfg phi alias). */
    private function ternaryArmsAssignIntoSlot(?Block $ifBlock, ?Block $elseBlock, int $slot): bool
    {
        foreach ([$ifBlock, $elseBlock] as $branch) {
            if (null === $branch) {
                continue;
            }
            foreach ($branch->opCodes as $branchOp) {
                if (OpCode::TYPE_ASSIGN === $branchOp->type && (int) $branchOp->arg2 === $slot) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Copy a property-backed value into a stack box so CONCAT cannot store back into the
     * live object slot (in-place CONCAT dest === fetch temp; #33849).
     */
    private function detachObjectPropertyStringForConcat(Variable $var): Variable
    {
        if (null === $var->objectPropertySlot && null === $var->objectPropertyName) {
            return $var;
        }

        return $this->reseatPropertyFetchReadIntoValueBox($var);
    }

    /**
     * Non-hashtable property reads must not leave objectPropertySlot on ASSIGN dest —
     * the next `$s = …` would propertyStore into the previous object (#34465 / peer #33849).
     * Hashtable props keep the alias for dim writes (#848).
     */
    private function isScalarObjectPropertyAliasType(?int $propertyType): bool
    {
        if (null === $propertyType) {
            return true;
        }
        if (Variable::TYPE_HASHTABLE === $propertyType) {
            return false;
        }

        return true;
    }

    /**
     * Reseat a non-hashtable property fetch into a stack box and drop the live-slot alias (#34465).
     */
    private function detachScalarObjectPropertyAliasForAssign(Variable $var): Variable
    {
        if (null === $var->objectPropertySlot) {
            return $var;
        }
        if (!$this->isScalarObjectPropertyAliasType($var->objectPropertyType)) {
            return $var;
        }
        $propType = $var->objectPropertyType ?? $var->type;
        if (\in_array($propType, [
            Variable::TYPE_NATIVE_LONG,
            Variable::TYPE_NATIVE_BOOL,
            Variable::TYPE_NATIVE_DOUBLE,
        ], true)) {
            return $this->snapshotNativeScalarPropertyRead($var, $propType);
        }
        $boxed = $this->reseatPropertyFetchReadIntoValueBox($var);
        $boxed->objectPropertySlot = null;
        $boxed->objectPropertyType = null;
        $boxed->objectPropertyReceiver = null;
        $boxed->objectPropertyReceiverOp = null;
        $boxed->objectPropertyName = null;
        $boxed->objectPropertyClassName = null;
        $boxed->objectPropertyDnfArms = null;
        $boxed->objectPropertyClassConstraint = null;
        $boxed->objectPropertyDeclaredTypeLabel = null;

        return $boxed;
    }

    /**
     * CONCAT operands that are ?: phi aliases must read the stack-phi dest (#32908 / #18052).
     *
     * Do not redirect when this block's CONCAT *defines* $slot (dest === $slot): php-cfg
     * folds `($o->prop . '=')` into in-place CONCAT($slot, $slot, lit) on the true arm —
     * the left operand is the property fetch, not the null-initialized merge phi (#33849).
     */
    private function resolveTernaryPhiConcatOperand(Block $block, int $slot): Operand
    {
        $hasPhiAlias = isset($this->context->coalesceMergeSlotOperands[$slot])
            || isset($this->context->ternaryEchoPhiByAliasSlot[$slot]);
        if ($hasPhiAlias) {
            $definesSlot = false;
            foreach ($block->opCodes as $op) {
                if (
                    OpCode::TYPE_CONCAT === $op->type
                    && null !== $op->arg1
                    && (int) $op->arg1 === $slot
                ) {
                    $definesSlot = true;
                    break;
                }
            }
            if (!$definesSlot) {
                if (isset($this->context->coalesceMergeSlotOperands[$slot])) {
                    return $this->context->coalesceMergeSlotOperands[$slot];
                }

                return $this->context->ternaryEchoPhiByAliasSlot[$slot];
            }
        }
        $op = $block->getOperand($slot);
        assert(null !== $op);

        return $op;
    }

    private function ternaryEchoPhiOperand(Block $mergeBlock, ?Block $ifBlock, ?Block $elseBlock): ?Operand
    {
        $resultSlot = $this->mergeTernaryResultSlot($mergeBlock, $ifBlock, $elseBlock);
        if (null === $resultSlot) {
            return null;
        }
        $mergeIsArgSendPhi = false;
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (
                OpCode::TYPE_ARG_SEND === $mergeOp->type
                && null !== $mergeOp->arg1
                && (int) $mergeOp->arg1 === $resultSlot
            ) {
                $mergeIsArgSendPhi = true;
                break;
            }
        }
        $mergePhi = $mergeIsArgSendPhi ? $mergeBlock->getOperand($resultSlot) : null;
        foreach ([$ifBlock, $elseBlock] as $branch) {
            if (null === $branch) {
                continue;
            }
            foreach ($branch->opCodes as $branchOp) {
                if (OpCode::TYPE_ASSIGN !== $branchOp->type) {
                    continue;
                }
                if ((int) $branchOp->arg2 !== $resultSlot) {
                    continue;
                }

                $armOp = $branch->getOperand($branchOp->arg2);
                // FUNCCALL ARG_SEND merges: arm INIT_ARRAY may mint a distinct Temporary at the
                // phi slot — coalesce must target the merge ARG_SEND operand (#34956).
                // ECHO merges keep the arm operand (#34814 / #32912).
                if (null !== $mergePhi && null !== $armOp && $armOp !== $mergePhi) {
                    return $mergePhi;
                }

                // Prefer the shared phi lvalue (arg2) over the per-arm Assign result temp
                // (arg1). Both ?: arms write arg2; only one arm's arg1 matches the first
                // hit — FuncCall arms then never forceCoalesce into the echo slot and AOT
                // echoes a stale name literal (#34814 / peer #18052 alias redirect).
                return $branch->getOperand($branchOp->arg2);
            }
        }

        return null;
    }

    /**
     * False when a JUMPIF arm has switch/call/echo side effects before its merge JUMP (#878).
     */
    private function branchIsTernaryReturnMergeArm(?Block $branch): bool
    {
        if (null === $branch) {
            return false;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_JUMP === $branchOp->type) {
                break;
            }
            if (
                OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $branchOp->type
                || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $branchOp->type
                || OpCode::TYPE_METHODCALL_INIT === $branchOp->type
                || OpCode::TYPE_STATICCALL_INIT === $branchOp->type
                || OpCode::TYPE_ECHO === $branchOp->type
            ) {
                return false;
            }
        }

        return true;
    }

    private function ternaryReturnPhiOperand(Block $mergeBlock): ?Operand
    {
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (OpCode::TYPE_RETURN === $mergeOp->type && null !== $mergeOp->arg1) {
                return $mergeBlock->getOperand($mergeOp->arg1);
            }
        }

        return null;
    }

    /** True when the branch assigns a string into the shared ?: phi (#8555). */
    private function branchAssignsStringToTernaryPhi(Block $branch, Block $mergeBlock): bool
    {
        $source = $this->ternaryPhiAssignSourceOperand($branch, $mergeBlock);
        if (null === $source) {
            return false;
        }

        return Variable::TYPE_STRING === Variable::getTypeFromType($source->type)
            || $this->operandTypeIncludesString($source);
    }

    /**
     * True when the branch assigns only null into the shared ?: phi (#8555).
     */
    private function branchAssignsOnlyNullToTernaryPhi(Block $branch, Block $mergeBlock): bool
    {
        $source = $this->ternaryPhiAssignSourceOperand($branch, $mergeBlock);
        if (null === $source) {
            return false;
        }

        return Variable::TYPE_NULL === Variable::getTypeFromType($source->type);
    }

    private function ternaryPhiAssignSourceOperand(Block $branch, Block $mergeBlock): ?Operand
    {
        $phi = $this->ternaryReturnPhiOperand($mergeBlock);
        if (null === $phi) {
            return null;
        }
        $phiSlot = $mergeBlock->slotForOperand($phi);
        if (null === $phiSlot) {
            return null;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_ASSIGN !== $branchOp->type) {
                continue;
            }
            // Incomplete ASSIGN operands (NestedJIT VmPregEngine ternaries) — skip (#24115 / #16075).
            $destOp = $branch->getOperand($branchOp->arg1);
            $aliasOp = $branch->getOperand($branchOp->arg2);
            if (null === $destOp && null === $aliasOp) {
                continue;
            }
            $destSlot = null !== $destOp ? $branch->slotForOperand($destOp) : null;
            $aliasSlot = null !== $aliasOp ? $branch->slotForOperand($aliasOp) : null;
            if ($destSlot !== $phiSlot && $aliasSlot !== $phiSlot) {
                continue;
            }

            return $branch->getOperand($branchOp->arg3);
        }

        return null;
    }

    private function operandTypeIncludesString(Operand $op): bool
    {
        $type = $op->type;
        if (null === $type) {
            return false;
        }
        if (\PHPTypes\Type::TYPE_STRING === $type->type) {
            return true;
        }
        foreach ($type->subTypes ?? [] as $sub) {
            if (\PHPTypes\Type::TYPE_STRING === ($sub->type ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** True when the branch only assigns null into the shared ?: phi (#8555). */
    private function branchAssignsNullToTernaryPhi(Block $branch, Block $mergeBlock): bool
    {
        $phi = $this->ternaryReturnPhiOperand($mergeBlock);
        if (null === $phi) {
            return false;
        }
        $phiSlot = $mergeBlock->slotForOperand($phi);
        if (null === $phiSlot) {
            return false;
        }
        if ($this->branchAssignsStringToTernaryPhi($branch, $mergeBlock)) {
            return false;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_ASSIGN !== $branchOp->type) {
                continue;
            }
            $destOp = $branch->getOperand($branchOp->arg1);
            $aliasOp = $branch->getOperand($branchOp->arg2);
            if (null === $destOp && null === $aliasOp) {
                continue;
            }
            $destSlot = null !== $destOp ? $branch->slotForOperand($destOp) : null;
            $aliasSlot = null !== $aliasOp ? $branch->slotForOperand($aliasOp) : null;
            if ($destSlot !== $phiSlot && $aliasSlot !== $phiSlot) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array{0: Block, 1: Block} compile order (non-string arm first)
     */
    private function ternaryReturnMergeCompileOrder(Block $ifBlock, Block $elseBlock, Block $mergeBlock): array
    {
        $ifString = $this->branchAssignsStringToTernaryPhi($ifBlock, $mergeBlock);
        $elseString = $this->branchAssignsStringToTernaryPhi($elseBlock, $mergeBlock);
        if ($ifString && !$elseString) {
            return [$elseBlock, $ifBlock];
        }
        if ($elseString && !$ifString) {
            return [$ifBlock, $elseBlock];
        }

        return [$ifBlock, $elseBlock];
    }

    private function ternaryArmAssignSourceVariable(Block $armBlock, Block $mergeBlock): ?Variable
    {
        $source = $this->ternaryPhiAssignSourceOperand($armBlock, $mergeBlock);
        if (null === $source) {
            return null;
        }
        if (
            Variable::TYPE_NULL === Variable::getTypeFromType($source->type)
            && !$this->operandTypeIncludesString($source)
        ) {
            return null;
        }

        return $this->context->getVariableFromOp($source);
    }


    /**
     * Lower CFG RETURN for a shared ?: phi at an arm tail (issue #8555).
     */
    private function emitCfgReturnOperand(
        PHPLLVM\Value\Function_ $func,
        Block $cfgBlock,
        Operand $returnOperand,
        PHPLLVM\BasicBlock $tailBlock,
        ?Variable $returnValue = null
    ): void {
        if (null !== $tailBlock->getTerminator()) {
            return;
        }
        if (null !== $returnValue) {
            $return = $returnValue;
        } else {
            $bound = $this->context->functionScopeBindingVariable($returnOperand, $cfgBlock);
            if (null !== $bound) {
                $return = $bound;
            } else {
                $return = $this->context->getVariableFromOp($returnOperand);
            }
        }
        $builder = $this->context->builder;
        $builder->positionAtEnd($tailBlock);
        $this->markJitThisConstructedIfLeavingConstruct($cfgBlock);
        if (
            0 === $this->context->inlineIncludeDepth
            && JIT\TryCatchHelper::deferReturnIfNeeded($this, $this->context, $func, $cfgBlock, false, $return)
        ) {
            return;
        }
        if ($cfgBlock->returnTypeVoid) {
            JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
            JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
            JIT\Builtin\TypeErrorRaise::emitRaise(
                $this->context,
                'A void function must not return a value'
            );

            return;
        }
        $return->addref();
        if (null !== $cfgBlock->returnDnfConstraints
            && !JIT\ClassReturnCheck::generatorSkipsBodyReturnCheck($cfgBlock)
        ) {
            JIT\DnfParamCheck::enforce(
                $this->context,
                $return,
                $cfgBlock->returnDnfConstraints,
                'Return value',
                $this->jitReturnTypeCallableName($cfgBlock)
            );
        }
        if (!$this->emitJitClassReturnTypeCheck($cfgBlock, $return)) {
            return;
        }
        if (!$this->emitJitScalarReturnTypeCheck($cfgBlock, $return)) {
            return;
        }
        $retval = $this->context->helper->loadValue($return);
        $expected = $this->cfgFunctionReturnCallbackType($cfgBlock->func);
        if (null === $expected && null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[strtolower($this->context->activeFunction)] ?? null;
        }
        $retval = $this->coerceReturnValue($return, $retval, $expected);
        $retval = $this->alignRetvalToLlvmFnReturn($retval, $func);
        // Arm-tail ?: returns must not use merge-block dead operands — they free branch
        // locals (e.g. string params) before coerceReturnValue finishes (#8555).
        if ($this->isVoidLlvmFunction($func)) {
            $builder->returnVoid();
        } elseif ($this->cfgFunctionReturnsByRef($cfgBlock->func)) {
            $builder->returnValue(
                JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
            );
        } else {
            $builder->returnValue($retval);
        }
    }
}
