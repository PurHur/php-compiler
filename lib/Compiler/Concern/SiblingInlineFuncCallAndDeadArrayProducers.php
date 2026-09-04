<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Sibling inline FuncCall ordinals and dead-arg INIT_ARRAY producers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers sibling/outer-sibling FuncCall producer ordinal wiring and
 * array_keys / array_multisort / dead-call-arg INIT_ARRAY producer lookup
 * used from compileCallArgSends.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait SiblingInlineFuncCallAndDeadArrayProducers
{
    private function siblingInlineFuncCallSkipsExecReturnOrdinal(
        Op $child,
        int $childIndex,
        array $cfgChildren
    ): bool {
        if (!$child instanceof Op\Expr\FuncCall && !$child instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if ($childIndex < 1) {
            return false;
        }
        $prev = $cfgChildren[$childIndex - 1] ?? null;
        if (!$prev instanceof Op\Expr\FuncCall && !$prev instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!$this->isAdjacentNestedFuncCallProducer($prev, $child, $childIndex - 1, $childIndex)) {
            return false;
        }
        if ('var_dump' !== strtolower($this->resolveCfgFuncCallName($child) ?? '')) {
            return false;
        }
        // var_dump($g(), $h()) — multi-arg sibling wiring still uses EXEC_RETURN (#16029).
        if (\is_array($child->args ?? null) && \count($child->args) >= 2) {
            return false;
        }

        return true;
    }

    private function siblingInlineFuncCallProducerOrdinal(
        int $producerIndex,
        int $firstSibling,
        array $cfgChildren,
        ?int $consumerIndex = null
    ): int {
        if (null === $consumerIndex) {
            // Bound: ordinal consumer sits near the producer (#36387).
            $n = \count($cfgChildren);
            $scanEnd = min($n, $producerIndex + 1 + 32);
            for ($k = $producerIndex + 1; $k < $scanEnd; ++$k) {
                $cand = $cfgChildren[$k] ?? null;
                if (!$this->isSiblingMultiArgInlineCallConsumer($cand)) {
                    continue;
                }
                // Skip 0-arg MethodCall leaves (isId) so ordinals use the outer multi-arg FuncCall
                // (var_export(..., true)) — otherwise UNKNOWN-typed receivers get ord=-1 (#25928).
                if (
                    ($cand instanceof Op\Expr\MethodCall || $cand instanceof Op\Expr\StaticCall)
                    && $this->deadInlineTemporaryArgCount($cand) < 1
                ) {
                    continue;
                }
                $consumerIndex = $k;
                break;
            }
        }
        $consumerIndex ??= $producerIndex + 1;
        $deadInlineArgCount = $this->deadInlineTemporaryArgCount($cfgChildren[$consumerIndex] ?? null);
        $ordinal = -1;
        for ($j = $firstSibling; $j <= $producerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($child)) {
                continue;
            }
            if (
                $child instanceof Op\Expr\MethodCall
                && $this->methodCallIsSkippedHoistedSiblingProducer(
                    $child,
                    $j,
                    $consumerIndex,
                    $deadInlineArgCount,
                    $cfgChildren
                )
            ) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            if (
                !($child instanceof Op\Expr\MethodCall)
                && $this->siblingInlineCallProducerSkipsHoistedArgChain($child, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            ++$ordinal;
        }

        return $ordinal;
    }

    /**
     * Outer hoisted sibling FuncCall producers — skip inner g() in f(g()) chains (#15488).
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr>
     */
    private function outerSiblingInlineFuncCallProducers(
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): array {
        $deadInlineArgCount = $this->deadInlineTemporaryArgCount($cfgChildren[$consumerIndex] ?? null);
        $producers = [];
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($child) || !$child instanceof Op\Expr) {
                continue;
            }
            if (
                $child instanceof Op\Expr\MethodCall
                && $this->methodCallIsSkippedHoistedSiblingProducer(
                    $child,
                    $j,
                    $consumerIndex,
                    $deadInlineArgCount,
                    $cfgChildren
                )
            ) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            $next = $cfgChildren[$j + 1] ?? null;
            if (
                $j + 1 < $consumerIndex
                && ($next instanceof Op\Expr\Assign || $next instanceof Op\Expr\AssignRef)
                && null !== $child->result
                && null !== $next->expr
                && $this->operandsReferToSameVariable($child->result, $next->expr)
            ) {
                // $loose = in_array(...); array_search(null, [null]) — stmt-level callee, not outer arg (#11058).
                continue;
            }
            if (
                $j + 1 < $consumerIndex
                && ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)
                && $this->isAdjacentNestedFuncCallProducer($child, $next, $j, $j + 1)
            ) {
                continue;
            }
            if (
                !($child instanceof Op\Expr\MethodCall)
                && $this->siblingInlineCallProducerSkipsHoistedArgChain($child, $next)
            ) {
                continue;
            }
            $producers[] = $child;
        }

        return $producers;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function outerSiblingInlineFuncCallProducerOrdinal(
        Op\Expr $producer,
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): ?int {
        foreach ($this->outerSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren) as $ordinal => $candidate) {
            if ($candidate === $producer) {
                return $ordinal;
            }
        }

        return null;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function siblingInlineFuncCallProducerIndexAtOrdinal(
        int $ordinal,
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): ?int {
        $seen = -1;
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($child)) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            ++$seen;
            if ($seen === $ordinal) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function siblingInlineFuncCallProducerOrdinalAtIndex(
        int $producerIndex,
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): ?int {
        $seen = -1;
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($child)) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            ++$seen;
            if ($j === $producerIndex) {
                return $seen;
            }
        }

        return null;
    }

    /**
     * @param list<Operand|null> $args
     */
    private function deadInlineTemporaryArgOrdinalBeforeIndex(array $args, int $argIndex): int
    {
        $ordinal = 0;
        for ($i = 0; $i < $argIndex; ++$i) {
            if ($this->callArgIsDeadInlineTemporary($args[$i] ?? null)) {
                ++$ordinal;
            }
        }

        return $ordinal;
    }

    private function callHasNamedVariableArgument(Op $cfgCallOp): bool
    {
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        foreach ($cfgCallOp->args as $callArg) {
            if ($callArg instanceof Operand && $this->isNamedVariableOperand($callArg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * array_multisort([..], $labels = [..]) — map dead inline arg index to hoisted Array_ (#15151).
     *
     * php-cfg lowers assign-in-call between the second literal and FuncCall; the first literal is
     * not stmt-immediate-before the call.
     */
    private function inlineArrayMultisortLiteralProducerForArg(?Op $cfgCallOp, Block $block, int $argIndex): ?Op\Expr\Array_
    {
        if (null === $cfgCallOp || null === $block->orig || $argIndex < 0) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $arrays = [];
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\Array_) {
                $arrays[] = $child;
            }
        }

        return $arrays[$argIndex] ?? null;
    }

    /**
     * array_diff_assoc(array_keys([..]), array_keys([..])) — hoisted dual Array_ preludes share stmt-before (#16418).
     */
    private function inlineArrayProducerForArrayKeysDeadCallArg(
        Operand $arg,
        Block $block,
        Op $cfgCallOp
    ): ?Op\Expr\Array_ {
        if ($this->callArgIsCoalesceMergeProducer($arg, $block, $cfgCallOp, 0)) {
            return null;
        }
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren && null !== $block->orig) {
            $cfgChildren = $block->orig->children;
        }
        if ([] === $cfgChildren) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!is_int($callIndex)) {
            return null;
        }
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $cfgChildren[$i] ?? null;
            if (
                $child instanceof Op\Expr\Array_
                && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $arg)
            ) {
                return $child;
            }
        }
        $arrayProducers = [];
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $cfgChildren[$i] ?? null;
            if ($child instanceof Op\Expr\Array_) {
                $arrayProducers[] = $child;
            }
        }
        if ([] === $arrayProducers) {
            return null;
        }
        $stmtBefore = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
        if ($stmtBefore instanceof Op\Expr\Array_) {
            $stmtIndex = array_search($stmtBefore, $arrayProducers, true);
            if (is_int($stmtIndex)) {
                return $arrayProducers[$stmtIndex];
            }
        }
        $priorArrayKeys = 0;
        for ($j = $callIndex - 1; $j >= 0; --$j) {
            $child = $cfgChildren[$j] ?? null;
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && 'array_keys' === $this->resolveCfgFuncCallName($child)
            ) {
                ++$priorArrayKeys;
                continue;
            }
            if (
                $child instanceof Op\Expr\Array_
                || $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $this->isUnaryInlineSiblingCallArgExpr($child)
            ) {
                continue;
            }
            break;
        }
        $arrayOrdinal = $priorArrayKeys;

        return $arrayProducers[$arrayOrdinal] ?? null;
    }

    /** Hoisted inline Array_ ordinal for array_keys() dead arg — CFG stmt index, not operand identity (#16418). */
    private function inlineArrayKeysHoistedArrayOrdinal(Block $block, Op $cfgCallOp): ?int
    {
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren && null !== $block->orig) {
            $cfgChildren = $block->orig->children;
        }
        if ([] === $cfgChildren) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!is_int($callIndex)) {
            return null;
        }
        $arrayCount = 0;
        for ($i = 0; $i < $callIndex; ++$i) {
            if (($cfgChildren[$i] ?? null) instanceof Op\Expr\Array_) {
                ++$arrayCount;
            }
        }
        if ($arrayCount < 1) {
            return null;
        }
        $stmtBefore = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
        if ($stmtBefore instanceof Op\Expr\Array_) {
            $stmtOrdinal = 0;
            for ($i = 0; $i < $callIndex; ++$i) {
                $child = $cfgChildren[$i] ?? null;
                if ($child instanceof Op\Expr\Array_) {
                    if ($child === $stmtBefore) {
                        return $stmtOrdinal;
                    }
                    ++$stmtOrdinal;
                }
            }
        }
        $priorArrayKeys = 0;
        for ($j = $callIndex - 1; $j >= 0; --$j) {
            $child = $cfgChildren[$j] ?? null;
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && 'array_keys' === $this->resolveCfgFuncCallName($child)
            ) {
                ++$priorArrayKeys;
                continue;
            }
            if (
                $child instanceof Op\Expr\Array_
                || $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $this->isUnaryInlineSiblingCallArgExpr($child)
            ) {
                continue;
            }
            break;
        }

        return $priorArrayKeys < $arrayCount ? $priorArrayKeys : null;
    }

    /** array_merge(['a'=>1], array_keys(...)) — two Array_ preludes before sibling array_keys() (#13760, #16418). */
    private function arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling(Block $block, Op $cfgCallOp): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $callee = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array($callee, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!is_int($callIndex)) {
            return false;
        }
        for ($mj = $callIndex - 1; $mj >= 0; --$mj) {
            $child = $block->orig->children[$mj] ?? null;
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && 'array_keys' === $this->resolveCfgFuncCallName($child)
            ) {
                $arrayCount = 0;
                for ($k = 0; $k < $mj; ++$k) {
                    if (($block->orig->children[$k] ?? null) instanceof Op\Expr\Array_) {
                        ++$arrayCount;
                    }
                }

                return $arrayCount >= 2;
            }
            if (
                $child instanceof Op\Expr\Array_
                || $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $this->isUnaryInlineSiblingCallArgExpr($child)
                || $this->isSiblingInlineCallProducerExpr($child)
            ) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * CFG index after the most recent completed u* diff/intersect stmt (#16045, re-#14021).
     *
     * @param list<Op> $cfgChildren
     */
    private function cfgStartIndexAfterLastTrailingComparatorStmt(int $beforeIndex, array $cfgChildren): int
    {
        for ($i = $beforeIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i] ?? null;
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * Skip INIT_ARRAY ordinals from inline Array_ preludes before a prior u* stmt (#14021).
     *
     * @param list<Op> $cfgChildren
     */
    private function initArrayOrdinalOffsetBeforeTrailingComparatorStmt(int $callIndex, array $cfgChildren): int
    {
        $cfgStart = $this->cfgStartIndexAfterLastTrailingComparatorStmt($callIndex, $cfgChildren);
        if ($cfgStart <= 0) {
            return 0;
        }
        $offset = 0;
        for ($i = 0; $i < $cfgStart; ++$i) {
            if (($cfgChildren[$i] ?? null) instanceof Op\Expr\Array_) {
                ++$offset;
            }
        }

        return $offset;
    }

    /** Nth TYPE_INIT_ARRAY in pending emits + block — hoisted sibling array_keys() preludes (#16418). */
    private function slotForInitArrayOrdinal(Block $block, int $targetOrdinal, array $pendingOps = []): ?string
    {
        if ($targetOrdinal < 0) {
            return null;
        }
        $seen = 0;
        foreach ($pendingOps as $op) {
            if (OpCode::TYPE_INIT_ARRAY !== $op->type) {
                continue;
            }
            if ($seen === $targetOrdinal) {
                return (string) $op->arg1;
            }
            ++$seen;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY !== $op->type) {
                continue;
            }
            if ($seen === $targetOrdinal) {
                return (string) $op->arg1;
            }
            ++$seen;
        }

        return null;
    }

    /**
     * php-cfg `array_keys([...])` hoists the literal Array_ stmt immediately before the call (#13778).
     * in_array('a', ['a','b'], true) may hoist ConstFetch between Array_ and FuncCall (#15422).
     */
    private function inlineArrayProducerImmediatelyBeforeCfgCall(?Op $callOp, Block $block): ?Op\Expr\Array_
    {
        if (null === $callOp) {
            return null;
        }
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        for ($probe = $callIndex - 1; $probe >= 0; --$probe) {
            $prev = $cfgChildren[$probe] ?? null;
            if ($prev instanceof Op\Expr\ConstFetch || $prev instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($prev instanceof Op\Expr\Cast) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($prev)) {
                continue;
            }
            if ($prev instanceof Op\Expr\Array_) {
                return $prev;
            }

            break;
        }

        return null;
    }

    /**
     * Array_ feeding a dead inline call arg — stmt-before, embedded literal, or cfg producer (#14516).
     */
    private function inlineArrayLiteralForDeadCallArg(Op $callOp, int $argIndex, Block $block): ?Op\Expr\Array_
    {
        if (
            'preg_replace_callback_array' === $this->resolveCfgFuncCallName($callOp)
            && 0 === $argIndex
        ) {
            $patternArg = $callOp->args[0] ?? null;
            if ($patternArg instanceof Operand) {
                $embedded = $this->unwrapArrayLiteralExpr($patternArg);
                if ($embedded instanceof Op\Expr\Array_) {
                    return $embedded;
                }
            }
            $immediate = $this->inlineArrayProducerImmediatelyBeforeCfgCall($callOp, $block);
            if ($immediate instanceof Op\Expr\Array_) {
                return $immediate;
            }
        }
        if (
            'proc_open' === $this->resolveCfgFuncCallName($callOp)
            && \in_array($argIndex, [0, 1], true)
            && \is_array($callOp->args ?? null)
        ) {
            if (0 === $argIndex) {
                $commandArg = $callOp->args[0] ?? null;
                if ($commandArg instanceof Operand) {
                    $embeddedCommand = $this->unwrapArrayLiteralExpr($commandArg);
                    if ($embeddedCommand instanceof Op\Expr\Array_) {
                        return $embeddedCommand;
                    }
                }
            }
            if (null !== $block->orig) {
                $procOpenProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $callOp
                );
                $matched = $this->matchInlineArrayProducersToArrayCallArgs(
                    $procOpenProducers,
                    $callOp->args,
                    $argIndex
                );
                if ($matched instanceof Op\Expr\Array_) {
                    return $matched;
                }
            }
        }
        $immediate = $this->inlineArrayProducerImmediatelyBeforeCfgCall($callOp, $block);
        if ($immediate instanceof Op\Expr\Array_) {
            if (null !== $block->orig) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
                if (2 === \count($producers)) {
                    $mapped = $this->matchArrayMergeFuncCallAndArrayInlineProducers($producers, $argIndex);
                    if ($mapped instanceof Op\Expr\Array_ && $mapped === $immediate) {
                        return $immediate;
                    }
                    if ($mapped instanceof Op\Expr\FuncCall || $mapped instanceof Op\Expr\NsFuncCall) {
                        return null;
                    }
                }
            }
            $callArg = $callOp->args[$argIndex] ?? null;
            if (
                null !== $callArg
                && null !== $immediate->result
                && $this->operandsReferToSameVariable($immediate->result, $callArg)
            ) {
                return $immediate;
            }
            // array_keys([...]) / array_diff_assoc(array_keys(...), array_keys(...)) — stmt-before Array_
            // for dead php-cfg temps without shared cfgVar roots (#13778, #13779, #15569).
            if (
                null !== $callArg
                && $this->callArgIsDeadInlineTemporary($callArg)
                && $this->callArgOperandExpectsArrayProducer($callArg)
                && !$this->inlineArrayLiteralStmtBeforeOverriddenBySiblingCallProducer(
                    $callOp,
                    $argIndex,
                    $block
                )
            ) {
                return $immediate;
            }
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand) {
            return null;
        }
        $embedded = $this->unwrapArrayLiteralExpr($callArg);
        if ($embedded instanceof Op\Expr\Array_) {
            return $embedded;
        }
        $producer = $this->findCfgProducerExprForOperand($callArg);

        return $producer instanceof Op\Expr\Array_ ? $producer : null;
    }


    /**
     * array_map(null, [[..]]) — null ConstFetch is callback arg #0, not a nested-call prelude (#9143, #15976).
     */
}
