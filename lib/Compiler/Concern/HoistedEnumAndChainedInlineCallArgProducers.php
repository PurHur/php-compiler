<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\ReferencableCheck;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;

/**
 * Hoisted enum/const call-arg preludes and chained concat/arithmetic producers (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers sole-write Temporary lookup, hoisted EnumCase/ConstFetch prelude matching,
 * and `$a.$b` / arithmetic BinaryOp chains used as inline call-arg producers from
 * compileCallArgSends / InlineCallArgProducerMatch.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait HoistedEnumAndChainedInlineCallArgProducers
{
    /** Sole write-op for a Temporary when php-cfg left a single producer in $operand->ops (#19439). */
    private function soleWriteExprForOperand(Operand $operand): ?Op\Expr
    {
        if (!isset($operand->ops) || !\is_array($operand->ops) || 1 !== \count($operand->ops)) {
            return null;
        }
        $write = $operand->ops[0] ?? null;

        return $write instanceof Op\Expr ? $write : null;
    }

    /**
     * Hoisted enum case fetches already feeding an array literal must not be reused for later calls (#8749).
     */
    private function hoistedEnumCaseFetchConsumedInCfg(Op\Expr\ClassConstFetch $fetch, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if ($child === $fetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\Assign) {
                if ($this->operandsReferToSameVariable($child->expr, $fetch->result)) {
                    return true;
                }

                continue;
            }
            if ($child instanceof Op\Expr && $this->cfgExprUsesOperand($child, $fetch->result)) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg hoists `E::A` before `E::A::class` when the case fetch only feeds `::class` (#9426, #9518).
     *
     * @param list<Op> $ops
     */
    private function isHoistedEnumCaseFetchOnlyForCaseClassPseudoConst(
        Op\Expr\ClassConstFetch $fetch,
        array $ops,
        int $index,
        Block $block
    ): bool {
        if (!$this->isCompileTimeEnumCaseClassConstFetch($fetch, $block)) {
            return false;
        }
        for ($j = $index + 1, $n = \count($ops); $j < $n; ++$j) {
            $later = $ops[$j];
            if (!$later instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            $pseudo = $this->staticNameFromOperand($later->name);
            if (null === $pseudo || 'class' !== strtolower($pseudo)) {
                continue;
            }
            if ($this->operandsReferToSameVariable($later->class, $fetch->result)) {
                return true;
            }
        }

        return false;
    }

    /** True when php-cfg left the operand as an embedded literal in the FuncCall. */
    private function isEmbeddedCallLiteralArg(?Operand $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        // BoundVariable / named CV temps unwrap to Literal(name) via unwrapCfgLiteralOperand —
        // that is the variable *name*, not an embedded call-arg literal. Treating them as
        // literals forceFreshVarSlot's an empty slot and breaks function-static (and any named
        // local) args to builtins like count/implode/json_encode (#28038, re-#15914).
        if (null !== Block::resolveVariableName($arg)) {
            return false;
        }
        if (null !== $this->unwrapCfgLiteralOperand($arg)) {
            return true;
        }
        $root = $this->unwrapOperandChain($arg);
        if ($root instanceof Op\Expr\ClassConstFetch) {
            $name = $this->staticNameFromOperand($root->name);
            if (null !== $name && 'class' === strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Call args that consume a hoisted ClassConstFetch slot skip embedded literals and inline enum fetches (#8933).
     */
    private function callArgUsesHoistedEnumPreludeSlot(?Operand $callArg): bool
    {
        if (null === $callArg || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        // Named CVs / arrow Phi captures are not enum/ConstFetch prelude slots (#31720).
        if (null !== Block::resolveVariableName($callArg)) {
            return false;
        }
        // extract([...], flags: EXTR_SKIP) — array arg must not steal hoisted ConstFetch (#16539).
        if ($this->callArgOperandExpectsArrayProducer($callArg)) {
            return false;
        }
        $root = $this->unwrapOperandChain($callArg);
        if ($root instanceof Temporary) {
            return true;
        }

        // php-cfg dead call-arg Variable temps (e.g. var_dump(E::A::class); #9426).
        return $root instanceof Operand\Variable && !$this->isNamedVariableOperand($callArg);
    }

    /**
     * True when a true/false/null ConstFetch sits immediately before the call (hoisted prelude).
     * Used to keep named CV args on compileOperand beside sibling null literals (#31720).
     */
    private function callHasTrailingHoistedBoolNullConstFetch(Op $cfgCallOp, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $block->orig->children[$i] ?? null;
            if ($prev instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($prev->name);

                return null !== $name
                    && \in_array(strtolower($name), ['true', 'false', 'null'], true);
            }
            break;
        }

        return false;
    }

    /**
     * Hoisted ConstFetch / ClassConstFetch / UnaryMinus|Plus stmts immediately before a call (#15899, #16523).
     *
     * @return list<Op\Expr\ConstFetch|Op\Expr\ClassConstFetch|Op\Expr\MagicScriptConst|Op\Expr\UnaryMinus|Op\Expr\UnaryPlus>
     */
    private function hoistedPreludeProducersImmediatelyBeforeCall(Op $callOp, Block $block): array
    {
        if (null === $block->orig) {
            return [];
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return [];
        }
        $producers = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            // Sparse / stale cfgChildren indices — sibling walkers use ?? null (#36387 FinalClassConstCheck).
            $child = $block->orig->children[$i] ?? null;
            if (null === $child) {
                continue;
            }
            if (
                $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $child instanceof Op\Expr\MagicScriptConst
            ) {
                array_unshift($producers, $child);
                continue;
            }
            if ($child instanceof Op\Expr\UnaryMinus || $child instanceof Op\Expr\UnaryPlus) {
                // fseek($stream, -1, SEEK_END) — UnaryMinus offset prelude before ConstFetch whence (#16523).
                array_unshift($producers, $child);
                continue;
            }
            if ($child instanceof Op\Expr\Assign) {
                break;
            }
            if ($this->isInlineExprCallArgProducer($child)) {
                break;
            }
            break;
        }

        return $producers;
    }

    /**
     * $q = setlocale(LC_ALL, null) — hoisted ConstFetch preludes sit before the Assign stmt (#10177).
     *
     * @return list<Op\Expr\ConstFetch|Op\Expr\ClassConstFetch>
     */
    private function hoistedPreludeProducersBeforeAssignStmt(Op $callOp, Block $block): array
    {
        if (null === $block->orig) {
            return [];
        }
        $walkFrom = null;
        foreach ($block->orig->children as $i => $child) {
            if (
                $child instanceof Op\Expr\Assign
                && null !== $child->expr
                && ($child->expr === $callOp || $this->exprContainsCfgOp($child->expr, $callOp))
            ) {
                $walkFrom = $i - 1;
                break;
            }
        }
        if (!\is_int($walkFrom)) {
            return [];
        }
        $producers = [];
        for ($i = $walkFrom; $i >= 0; --$i) {
            $child = $block->orig->children[$i] ?? null;
            if (null === $child) {
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                array_unshift($producers, $child);
                continue;
            }
            if ($child instanceof Op\Expr\Assign) {
                break;
            }
            if ($this->isInlineExprCallArgProducer($child)) {
                break;
            }
            break;
        }

        return $producers;
    }

    private function exprContainsCfgOp(Op\Expr $expr, Op $needle): bool
    {
        if ($expr === $needle) {
            return true;
        }
        if (!property_exists($expr, 'expr') || !$expr->expr instanceof Op\Expr) {
            return false;
        }

        return $this->exprContainsCfgOp($expr->expr, $needle);
    }

    private function hoistedConstPreludeProducerForCallArgIndex(Op $callOp, int $argIndex, Block $block): ?Op\Expr
    {
        $callArg = $callOp->args[$argIndex] ?? null;
        if ($callArg instanceof Operand && $this->callArgOperandExpectsArrayProducer($callArg)) {
            return null;
        }
        // var_export(f(), true) / var_export($o->m(), true) — ConstFetch true sits between nested call and consumer (#16556, #17251).
        if (0 === $argIndex && null !== $block->orig) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
            if (null !== $callIndex) {
                for ($i = $callIndex - 1; $i >= 0; --$i) {
                    $prev = $block->orig->children[$i] ?? null;
                    if ($prev instanceof Op\Expr\ConstFetch || $prev instanceof Op\Expr\ClassConstFetch) {
                        continue;
                    }
                    if (
                        $prev instanceof Op\Expr\FuncCall
                        || $prev instanceof Op\Expr\NsFuncCall
                        || $prev instanceof Op\Expr\MethodCall
                        || $prev instanceof Op\Expr\StaticCall
                    ) {
                        $consumerFn = strtolower($this->resolveCfgFuncCallName($callOp) ?? '');
                        if ('var_export' === $consumerFn) {
                            if (
                                ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall)
                                && 'define' === strtolower($this->resolveCfgFuncCallName($prev) ?? '')
                            ) {
                                break;
                            }
                            $callArgZero = $callOp->args[0] ?? null;
                            if (
                                $callArgZero instanceof Operand
                                && null !== $prev->result
                                && (
                                    $callArgZero === $prev->result
                                    || $this->operandsReferToSameVariable($callArgZero, $prev->result)
                                )
                            ) {
                                return null;
                            }
                        }
                        if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                            $fn = $this->resolveCfgFuncCallName($prev);
                            if (null !== $fn && ReferencableCheck::isArrayInternalPointerBuiltin($fn)) {
                                return null;
                            }
                        }
                    }
                    break;
                }
            }
        }
        if (!$this->callArgHasHoistedConstPrelude($callOp, $argIndex, $block)) {
            return null;
        }
        $preludes = $this->hoistedPreludeProducersImmediatelyBeforeCall($callOp, $block);
        if ([] === $preludes) {
            $preludes = $this->hoistedPreludeProducersBeforeAssignStmt($callOp, $block);
        }
        $preludeOrdinal = 0;
        foreach ($callOp->args as $i => $callArg) {
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                continue;
            }
            if ($callArg instanceof Operand && $this->callArgOperandExpectsArrayProducer($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                $prelude = $preludes[$preludeOrdinal] ?? null;

                return $prelude instanceof Op\Expr\ConstFetch
                    || $prelude instanceof Op\Expr\ClassConstFetch
                    || $prelude instanceof Op\Expr\UnaryMinus
                    || $prelude instanceof Op\Expr\UnaryPlus
                    ? $prelude
                    : null;
            }
            ++$preludeOrdinal;
        }

        return null;
    }

    private function hoistedPreludeProducerForCallArgIndex(Op $callOp, int $argIndex, Block $block): ?Op\Expr
    {
        $ordinal = $this->hoistedEnumPreludeSlotOrdinalForCallArg($callOp, $argIndex);
        if (null === $ordinal) {
            return null;
        }
        if (
            $this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $callOp, $argIndex)
            || $this->nestedFuncCallFeedsDeadInlineCallArg($block, $callOp, $argIndex)
        ) {
            return null;
        }
        $producers = $this->hoistedPreludeProducersImmediatelyBeforeCall($callOp, $block);
        if (null !== $block->orig) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
            if (\is_int($callIndex)) {
                $nestedForArgZero = $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                    $callOp,
                    $callIndex,
                    $block->orig->children
                );
                if (null !== $nestedForArgZero) {
                    if (0 === $argIndex) {
                        // tempnam(g(), E::A) — nested FuncCall feeds arg #0, not trailing enum (#10303, #16558).
                        return null;
                    }
                    $nestedIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $nestedForArgZero, $block->orig);
                    if (\is_int($nestedIndex)) {
                        $targetArg = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                            $nestedIndex,
                            $callIndex,
                            $block->orig->children
                        );
                        if (null !== $targetArg && $targetArg === $argIndex) {
                            // unpack('i', pack(...), E::A) — middle arg is nested FuncCall (#8866).
                            return null;
                        }
                    }
                    $sole = $producers[0] ?? null;

                    return $sole instanceof Op\Expr ? $sole : null;
                }
            }
        }

        return $producers[$ordinal] ?? null;
    }

    /**
     * Map call arg index to hoisted ClassConstFetch when php-cfg inserts literal args first (#8796, #8933).
     *
     * @param list<Op\Expr\ClassConstFetch> $precedingFetches
     */
    private function precedingClassConstFetchForCallArgIndex(
        Op $callOp,
        int $argIndex,
        array $precedingFetches
    ): ?Op\Expr\ClassConstFetch {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $fetchIndex = 0;
        foreach ($callOp->args as $i => $callArg) {
            if (!$this->callArgUsesHoistedEnumPreludeSlot($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                if (
                    1 === \count($precedingFetches)
                    && $i < \count($callOp->args) - 1
                ) {
                    // Sole hoisted enum feeds trailing arg when arg #0 is a nested FuncCall (#10303, #16558).
                    return null;
                }
                $fetch = $precedingFetches[$fetchIndex] ?? null;
                // tempnam(sys_get_temp_dir(), E::A) — sole enum prelude feeds trailing arg (#10303, #16558).
                if (
                    0 === $argIndex
                    && 1 === \count($precedingFetches)
                    && 2 === \count($callOp->args)
                ) {
                    $hoistedArgIndices = [];
                    foreach ($callOp->args as $hi => $ha) {
                        if ($this->callArgUsesHoistedEnumPreludeSlot($ha)) {
                            $hoistedArgIndices[] = (int) $hi;
                        }
                    }
                    if (
                        \count($hoistedArgIndices) >= 2
                        && ($hoistedArgIndices[1] ?? null) === \count($callOp->args) - 1
                    ) {
                        return null;
                    }
                }
                // Trailing enum case when an earlier arg uses a nested FuncCall (#10303).
                if (
                    null === $fetch
                    && 1 === \count($precedingFetches)
                    && $i === \count($callOp->args) - 1
                ) {
                    $fetch = $precedingFetches[0];
                }
                if ($fetch instanceof Op\Expr\ClassConstFetch) {
                    $callArg = $callOp->args[$argIndex] ?? null;
                    // php-cfg dead call-arg temps: ordinal mapping is authoritative (#8796, #9888).
                    if (
                        null !== $callArg
                        && !$this->operandsReferToSameVariable($fetch->result, $callArg)
                        && !$this->callArgUsesHoistedEnumPreludeSlot($callArg)
                    ) {
                        return null;
                    }
                }

                return $fetch;
            }
            ++$fetchIndex;
        }

        return null;
    }

    /** Ordinal among call args that use hoisted enum prelude slots (skips embedded literals, #8933). */
    private function hoistedEnumPreludeSlotOrdinalForCallArg(Op $callOp, int $argIndex): ?int
    {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $fetchIndex = 0;
        foreach ($callOp->args as $i => $callArg) {
            if (!$this->callArgUsesHoistedEnumPreludeSlot($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                return $fetchIndex;
            }
            ++$fetchIndex;
        }

        return null;
    }



    /**
     * php-cfg hoists chained assignment before a call with a dead arg temp (#6758, #9405).
     *
     * @param list<Op\Expr> $producers
     */
    private function producersAreChainedAssignChain(array $producers): bool
    {
        if ([] === $producers) {
            return false;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\Assign) {
                return false;
            }
        }
        for ($i = 1, $n = count($producers); $i < $n; ++$i) {
            $inner = $producers[$i - 1];
            $outer = $producers[$i];
            if (!$this->operandsReferToSameVariable($inner->result, $outer->expr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * php-cfg hoists chained Concat before inline call args — wire final result slot (#13458, zend_operators.c).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchChainedConcatInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr {
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
        if (null === $soleHoisted || $argIndex !== $soleHoisted) {
            return null;
        }
        if ($this->producersAreChainedConcatProducers($producers)) {
            return $producers[\count($producers) - 1];
        }
        if (
            1 === \count($producers)
            && ($producers[0] ?? null) instanceof Op\Expr\BinaryOp\Concat
        ) {
            return $producers[0];
        }

        return null;
    }

    /**
     * php-cfg dead call-arg temp for chained Concat before FuncCall (#13458, #13572).
     *
     * `fopen('/tmp/maint_' . 99 . '/sub/file.txt', 'r')` — arg temp may differ from final Concat.result.
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr\BinaryOp\Concat>|null
     */
    private function chainedConcatInlineCallArgProducersBeforeCall(
        array $cfgChildren,
        int $callIndex,
        Op $callOp
    ): ?array {
        if ($callIndex < 1 || !property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callOp->args);
        if (null === $soleHoisted) {
            return null;
        }
        $callArg = $callOp->args[$soleHoisted] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $immediate = $cfgChildren[$callIndex - 1] ?? null;
        if (!$immediate instanceof Op\Expr\BinaryOp\Concat) {
            return null;
        }
        $chain = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i];
            if (!$child instanceof Op\Expr\BinaryOp\Concat) {
                break;
            }
            array_unshift($chain, $child);
            if ($i > 0) {
                $prev = $cfgChildren[$i - 1];
                if (
                    $prev instanceof Op\Expr\BinaryOp\Concat
                    && null !== $child->left
                    && $this->operandsReferToSameVariable($prev->result, $child->left)
                ) {
                    continue;
                }
            }
            break;
        }
        if ([] === $chain) {
            return null;
        }
        if (1 === \count($chain)) {
            return $chain;
        }
        if (!$this->producersAreChainedConcatProducers($chain)) {
            return null;
        }

        return $chain;
    }

    /**
     * php-cfg hoists `sprintf('%.10F', 5 * 200.0 / 12)` as sibling Mul/Div before FuncCall (#15929).
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr\BinaryOp\Div|Op\Expr\BinaryOp\Minus|Op\Expr\BinaryOp\Mul|Op\Expr\BinaryOp\Plus>|null
     */
    private function chainedArithmeticInlineCallArgProducersBeforeCall(
        array $cfgChildren,
        int $callIndex,
        Op $callOp
    ): ?array {
        if ($callIndex < 1 || !property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callOp->args);
        if (null === $soleHoisted) {
            return null;
        }
        $callArg = $callOp->args[$soleHoisted] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $immediate = $cfgChildren[$callIndex - 1] ?? null;
        if (!$this->isChainedArithmeticBinaryOpExpr($immediate)) {
            return null;
        }
        $chain = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i];
            if (!$this->isChainedArithmeticBinaryOpExpr($child)) {
                break;
            }
            array_unshift($chain, $child);
            if ($i > 0) {
                $prev = $cfgChildren[$i - 1];
                if (
                    $this->isChainedArithmeticBinaryOpExpr($prev)
                    && null !== $child->left
                    && $this->operandsReferToSameVariable($prev->result, $child->left)
                ) {
                    continue;
                }
            }
            break;
        }
        if ([] === $chain) {
            return null;
        }
        if (1 === \count($chain)) {
            return $chain;
        }
        if (!$this->producersAreChainedArithmeticProducers($chain)) {
            return null;
        }

        return $chain;
    }

    private function isChainedArithmeticBinaryOpExpr(?Op $expr): bool
    {
        return $expr instanceof Op\Expr\BinaryOp\Plus
            || $expr instanceof Op\Expr\BinaryOp\Minus
            || $expr instanceof Op\Expr\BinaryOp\Mul
            || $expr instanceof Op\Expr\BinaryOp\Div
            || $expr instanceof Op\Expr\BinaryOp\Mod
            || $expr instanceof Op\Expr\BinaryOp\Pow
            || $expr instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $expr instanceof Op\Expr\BinaryOp\BitwiseOr
            || $expr instanceof Op\Expr\BinaryOp\BitwiseXor
            || $expr instanceof Op\Expr\BinaryOp\ShiftLeft
            || $expr instanceof Op\Expr\BinaryOp\ShiftRight;
    }

    /**
     * @param list<Op\Expr> $producers
     */
    private function producersAreChainedArithmeticProducers(array $producers): bool
    {
        if (\count($producers) < 2) {
            return false;
        }
        foreach ($producers as $producer) {
            if (!$this->isChainedArithmeticBinaryOpExpr($producer)) {
                return false;
            }
        }
        for ($i = 1, $n = \count($producers); $i < $n; ++$i) {
            $inner = $producers[$i - 1];
            $outer = $producers[$i];
            if (
                null === $outer->left
                || !$this->operandsReferToSameVariable($inner->result, $outer->left)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Op\Expr> $producers
     */
    private function producersAreChainedConcatProducers(array $producers): bool
    {
        if (\count($producers) < 2) {
            return false;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\BinaryOp\Concat) {
                return false;
            }
        }
        for ($i = 1, $n = \count($producers); $i < $n; ++$i) {
            $inner = $producers[$i - 1];
            $outer = $producers[$i];
            if (
                null === $outer->left
                || !$this->operandsReferToSameVariable($inner->result, $outer->left)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * php-cfg hoists chained Mul/Div/Plus/Minus before inline call args — wire final result slot (#15929).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchChainedArithmeticInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr {
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
        if (null === $soleHoisted || $argIndex !== $soleHoisted) {
            return null;
        }
        if ($this->producersAreChainedArithmeticProducers($producers)) {
            return $producers[\count($producers) - 1];
        }
        if (
            1 === \count($producers)
            && $this->isChainedArithmeticBinaryOpExpr($producers[0] ?? null)
        ) {
            return $producers[0];
        }

        return null;
    }

}
