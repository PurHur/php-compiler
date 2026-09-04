<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Finalize array-family / filter_input call-arg slots + adjacent assign emit (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see finalizeFilterInputCallArgSlot}, {@see finalizeArrayMergeFamilyCallArgSlot},
 * {@see finalizeArrayCombineCallArgSlot}, {@see finalizeArrayColumnCallArgSlot},
 * {@see preferNamedLocalCallArgSlot}, {@see tryEmitAdjacentAssignForInlineCallArg},
 * and the block-assign helpers they share.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as CompileCallArgSends / EchoCoalesceCallArgCompile).
 */
trait FinalizeArrayFamilyCallArgSlots
{
    /**
     * Last-chance ARG_SEND slots for filter_input() hoisted ConstFetch / nested options (#15194).
     *
     * @param list<OpCode> $pendingSends
     */
    private function finalizeFilterInputCallArgSlot(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        array &$pendingSends = []
    ): ?string {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        if ('filter_input' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return null;
        }
        if (3 === $argIndex) {
            $optionsArg = $cfgCallOp->args[3] ?? null;
            if (
                $optionsArg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($optionsArg)
                && $this->callArgOperandExpectsArrayProducer($optionsArg)
            ) {
                return $this->resolveOutermostInitArraySlotBeforePendingFuncCall($block, $pendingSends);
            }

            return null;
        }
        if (0 !== $argIndex && 2 !== $argIndex) {
            return null;
        }
        $hoisted = [];
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ConstFetch) {
                array_unshift($hoisted, $child);
                continue;
            }
            if ($child instanceof Op\Expr\Array_) {
                continue;
            }
            if ($child instanceof Op\Expr\Assign) {
                break;
            }
            break;
        }
        $constFetches = array_values(array_filter(
            $hoisted,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ConstFetch
        ));
        $target = match ($argIndex) {
            0 => $constFetches[0] ?? null,
            2 => $constFetches[1] ?? ($constFetches[0] ?? null),
            default => null,
        };
        if (!$target instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $folded = $this->tryFoldGlobalConstFetch($target);
        if (null !== $folded) {
            return (string) $block->registerConstant(new Operand\Temporary(), $folded);
        }
        $slot = $block->slotForOperand($target->result);
        if (null !== $slot) {
            return (string) $slot;
        }
        foreach ($this->compileExpr($target, $block) as $op) {
            $pendingSends[] = $op;
        }
        $slot = $block->slotForOperand($target->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * Last keyed INIT_ARRAY slot for nested inline array call args (#11485).
     *
     * @param list<OpCode> $pendingSends
     */
    private function resolveOutermostInitArraySlotBeforePendingFuncCall(
        Block $block,
        array $pendingSends = []
    ): ?string {
        $outerSlot = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1 && null !== $op->arg3) {
                $outerSlot = (string) $op->arg1;
            }
        }
        if (null !== $outerSlot) {
            return $outerSlot;
        }
        $scanOps = array_merge($block->opCodes, $pendingSends);
        for ($i = \count($scanOps) - 1; $i >= 0; --$i) {
            $op = $scanOps[$i];
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    /**
     * Nth FUNCCALL_EXEC_RETURN slot including pending call-arg producer ops (#16097).
     *
     * @param list<OpCode> $pendingOps
     */
    private function slotForFuncCallExecReturnOrdinal(
        Block $block,
        int $producerOrdinal,
        array $pendingOps = []
    ): ?string {
        if ($producerOrdinal < 0) {
            return null;
        }
        $execReturnSlots = $block->funccallExecReturnSlots();
        if ([] !== $pendingOps) {
            foreach ($pendingOps as $op) {
                if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                    $execReturnSlots[] = (int) $op->arg1;
                }
            }
        }

        return isset($execReturnSlots[$producerOrdinal])
            ? (string) $execReturnSlots[$producerOrdinal]
            : null;
    }

    /**
     * Last-chance ARG_SEND slot for array_merge*(array_keys(...), [...]) sibling producers (#12450, #13704, #17781).
     *
     * @param list<OpCode> $sends
     */
    private function finalizeArrayMergeFamilyCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        $callee = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array(
            $callee,
            ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
            true
        )) {
            return null;
        }
        if (\count($cfgCallOp->args ?? []) < 2 || null === $block->orig) {
            return null;
        }
        $producers = $this->arrayMergeFamilyInlineProducersForCfgCall(
            $block->orig->children,
            $cfgCallOp
        );
        $matched = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
            $producers,
            $argIndex,
            \count($cfgCallOp->args ?? []),
            $cfgCallOp->args ?? []
        );
        if (null === $matched) {
            $matched = $this->matchArrayMergeFuncCallAndArrayInlineProducers($producers, $argIndex);
        }
        if (!$matched instanceof Op\Expr) {
            return null;
        }
        if ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall) {
            $funcOrdinal = 0;
            foreach ($producers as $producer) {
                if ($producer === $matched) {
                    break;
                }
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    ++$funcOrdinal;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            if (null === $block->slotForOperand($matched->result)) {
                foreach ($this->compileExpr($matched, $block) as $op) {
                    $sends[] = $op;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            $slot = $block->slotForOperand($matched->result);
            if (null !== $slot) {
                return (string) $slot;
            }

            return null;
        }
        if (null === $block->slotForOperand($matched->result)) {
            if ($matched instanceof Op\Expr\Array_) {
                foreach ($this->compileArrayLiteral($matched, $block) as $op) {
                    $sends[] = $op;
                }
            } else {
                foreach ($this->compileExpr($matched, $block) as $op) {
                    $sends[] = $op;
                }
            }
        }
        $slot = $block->slotForOperand($matched->result);
        if (null !== $slot) {
            return (string) $slot;
        }

        return null;
    }

    /**
     * Last-chance ARG_SEND slot for array_combine() nested array_keys() + trailing Array_ (#15558, #15857).
     *
     * @param list<OpCode> $sends
     */
    private function finalizeArrayCombineCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        if (2 !== \count($cfgCallOp->args ?? []) || null === $block->orig) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp
        );
        $matched = $this->matchArrayCombineInlineProducers($producers, $argIndex);
        if (!$matched instanceof Op\Expr) {
            return null;
        }
        if ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall) {
            $funcOrdinal = 0;
            foreach ($producers as $producer) {
                if ($producer === $matched) {
                    break;
                }
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    ++$funcOrdinal;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            if (null === $block->slotForOperand($matched->result)) {
                foreach ($this->compileExpr($matched, $block) as $op) {
                    $sends[] = $op;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            $slot = $block->slotForOperand($matched->result);
            if (null !== $slot) {
                return (string) $slot;
            }

            return null;
        }
        if (null === $block->slotForOperand($matched->result)) {
            foreach ($this->compileExpr($matched, $block) as $op) {
                $sends[] = $op;
            }
        }
        if ($matched instanceof Op\Expr\Array_) {
            $ordinalSlot = $this->slotForArrayCombineSiblingInitArray(
                $block,
                $producers,
                $argIndex,
                $sends
            );
            if (null !== $ordinalSlot) {
                return $ordinalSlot;
            }
            $byArgSlot = $this->slotForArrayCombineInitArrayByArgIndex($block, $cfgCallOp, $argIndex, $sends);
            if (null !== $byArgSlot) {
                return $byArgSlot;
            }
        }
        $slot = $block->slotForOperand($matched->result);
        if (null !== $slot) {
            return (string) $slot;
        }

        return null;
    }

    /**
     * array_combine() with inline array literals — map arg index to INIT_ARRAY ordinal (#16080).
     *
     * @param list<OpCode> $pendingSends
     */
    private function slotForArrayCombineInitArrayByArgIndex(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array $pendingSends
    ): ?string {
        if (2 !== \count($cfgCallOp->args ?? [])) {
            return null;
        }
        foreach ($cfgCallOp->args as $callArg) {
            if (
                null === $callArg
                || !$this->callArgIsDeadInlineTemporary($callArg)
                || !$this->callArgOperandExpectsArrayProducer($callArg)
            ) {
                return null;
            }
        }
        $initSlots = $this->initArraySlotsForCurrentFunccall($block, $pendingSends);
        if (\count($initSlots) <= $argIndex) {
            return null;
        }

        return $initSlots[$argIndex];
    }

    /**
     * array_combine([...], [...]) — map arg index to sibling INIT_ARRAY slot (#16080, #10214).
     *
     * @param list<OpCode> $pendingSends
     */
    private function slotForArrayCombineSiblingInitArray(
        Block $block,
        array $producers,
        int $argIndex,
        array $pendingSends
    ): ?string {
        $matched = $this->matchArrayCombineInlineProducers($producers, $argIndex);
        if (!$matched instanceof Op\Expr\Array_) {
            return null;
        }
        $arrayProducers = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
        ));
        if (2 !== \count($arrayProducers)) {
            return null;
        }
        $ordinal = array_search($matched, $arrayProducers, true);
        if (false === $ordinal) {
            return null;
        }
        $initSlots = $this->initArraySlotsForCurrentFunccall($block, $pendingSends);

        return $initSlots[$ordinal] ?? null;
    }

    /**
     * Last-chance ARG_SEND slot for array_column() inline nested haystack + hoisted null (#15914).
     *
     * @param list<OpCode> $sends
     */
    private function finalizeArrayColumnCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp
        );
        $callArgs = $cfgCallOp->args ?? [];
        $matched = $this->matchArrayColumnNestedHaystackTrailingProducers(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp
        );
        // array_column([['n'=>'a'], …], 'n') — nested haystack without trailing null (#13703, #15960).
        if (!$matched instanceof Op\Expr && 0 === $argIndex) {
            $matched = $this->matchFoldedFirstNestedSiblingArrayLiteralCallArgProducer(
                $producers,
                $argIndex,
                \count($callArgs),
                $callArgs
            );
            if (null === $matched) {
                $matched = $this->matchSoleNestedInlineArrayHaystackProducer(
                    $producers,
                    $callArgs,
                    $argIndex
                );
            }
            if (null === $matched) {
                $matched = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
            }
        }
        if (!$matched instanceof Op\Expr) {
            return null;
        }
        if (null === $block->slotForOperand($matched->result)) {
            foreach ($this->compileExpr($matched, $block) as $op) {
                $sends[] = $op;
            }
        }
        $slot = $block->slotForOperand($matched->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * array_column([[..]], null, 'x') / array_column([[..]], 'name', null) — nested haystack + null (#15914).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchArrayColumnNestedHaystackTrailingProducers(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp
    ): ?Op\Expr {
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null === $nestedTrailing) {
            return null;
        }
        [$arrayChain, $trailing] = $nestedTrailing;
        if (0 === $argIndex) {
            return $arrayChain[\count($arrayChain) - 1];
        }
        if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
            return null;
        }
        $nullFetch = null;
        foreach ($trailing as $producer) {
            if (!$producer instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = $this->staticNameFromOperand($producer->name);
            if (null !== $name && 'null' === strtolower($name)) {
                $nullFetch = $producer;
                break;
            }
        }
        if (null === $nullFetch) {
            return null;
        }
        $nullTarget = $this->arrayColumnNullPreludeArgIndex($cfgCallOp);
        if (null !== $nullTarget && $argIndex === $nullTarget) {
            return $nullFetch;
        }
        if ($argIndex === \count($callArgs) - 1) {
            return $nullFetch;
        }

        return null;
    }

    /**
     * Hoisted null ConstFetch before array_column() maps to column_key or index_key (#4306, #9305, #10535).
     */
    private function arrayColumnNullPreludeArgIndex(?Op $cfgCallOp): ?int
    {
        if (null === $cfgCallOp || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $args = $cfgCallOp->args;
        $argc = \count($args);
        if (2 === $argc) {
            return 1;
        }
        if (3 !== $argc) {
            return null;
        }
        $columnEmbedded = $this->isEmbeddedCallLiteralArg($args[1] ?? null);
        $indexEmbedded = $this->isEmbeddedCallLiteralArg($args[2] ?? null);
        if ($columnEmbedded && !$indexEmbedded) {
            return 2;
        }
        if (!$columnEmbedded && $indexEmbedded) {
            return 1;
        }

        return null;
    }

    /**
     * Named locals after ?: echo must not be remapped to merge-phi producer temps (#9487).
     */
    private function namedLocalCallArgSlotIfBound(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp = null,
        ?int $argIndex = null
    ): ?string {
        $probe = $arg;
        if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null) && isset($cfgCallOp->args[(int) $argIndex])) {
            $probe = $cfgCallOp->args[(int) $argIndex];
        }
        $name = Block::resolveVariableName($probe);
        if (null === $name || '' === $name) {
            $root = Block::cfgVarRoot($probe);
            if ($root instanceof CfgVariable) {
                $name = Block::resolveVariableName($root);
            }
        }
        if (null === $name || '' === $name) {
            $assignedNamed = $this->slotForNamedLocalFromAssignVarOperand($probe, $block);
            if (null !== $assignedNamed) {
                return (string) $assignedNamed;
            }
            return null;
        }
        $namedSlot = $block->slotIndexForVariableName($name);
        if (null === $namedSlot || !$block->isNamedVariableSlot((int) $namedSlot)) {
            return null;
        }

        return (string) $namedSlot;
    }

    /**
     * php-cfg may wire a later named local read to a preceding call's dead result temp (#9074).
     */
    private function preferNamedLocalCallArgSlot(
        Operand $arg,
        Block $block,
        ?string $valueSlot,
        ?string $calleeName = null
    ): ?string
    {
        if (null === $valueSlot) {
            return null;
        }
        $assignedNamed = $this->slotForNamedLocalFromAssignVarOperand($arg, $block);
        if (null !== $assignedNamed) {
            return (string) $assignedNamed;
        }
        if (
            $this->callArgOperandIsClosureValue($arg, $block)
            && !$this->isNamedVariableOperand($arg)
            && null === $this->namedLocalCallArgSlotIfBound($arg, $block)
        ) {
            return $valueSlot;
        }
        $name = Block::resolveVariableName($arg);
        if (null === $name || '' === $name) {
            $root = Block::cfgVarRoot($arg);
            if ($root instanceof CfgVariable) {
                $name = Block::resolveVariableName($root);
            }
        }
        if (null === $name || '' === $name) {
            return $valueSlot;
        }
        if (null !== $calleeName && $name === $calleeName) {
            return $valueSlot;
        }
        // php-cfg dead temps for hoisted scalar ConstFetch / Cast preludes (#9140, #10143).
        if (\in_array(strtolower($name), ['true', 'false', 'null', 'nan', 'inf'], true)) {
            return $valueSlot;
        }
        $namedSlot = $block->slotIndexForVariableName($name);
        if (null === $namedSlot) {
            return $valueSlot;
        }
        if (!$block->isNamedVariableSlot((int) $namedSlot)) {
            return $valueSlot;
        }
        if ((int) $namedSlot === (int) $valueSlot) {
            return $valueSlot;
        }
        // Inline producer temp must not replace an unbound named local (#9973, #9924).
        // Function-local statics bind via TYPE_DECLARE_FUNCTION_STATIC, not ASSIGN (#28038).
        if (
            !$this->blockHasAssignToSlot($block, (int) $namedSlot)
            && !$this->blockHasAssignToSlotInParentBlocks($block, (int) $namedSlot)
            && !$this->blockHasFunctionStaticDeclareToSlot($block, (int) $namedSlot)
        ) {
            return $valueSlot;
        }

        return $namedSlot;
    }

    /**
     * `$path = __DIR__ . '/x'; f($path)` — bind the named local when Concat is inlined (#9973).
     *
     * @return list<OpCode>
     */
    private function tryEmitAdjacentAssignForInlineCallArg(
        Operand $arg,
        ?string $valueSlot,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): array {
        if (null === $valueSlot || null === $cfgCallOp || null === $block->orig) {
            return [];
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return [];
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (null === $callArg || !$this->operandsReferToSameVariable($arg, $callArg)) {
            return [];
        }
        $children = $block->orig->children;
        $prev = null;
        foreach ($children as $i => $child) {
            if (
                !($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                || !property_exists($child, 'args')
                || !is_array($child->args)
            ) {
                continue;
            }
            if ($child !== $cfgCallOp) {
                $sameCall = false;
                if (
                    property_exists($cfgCallOp, 'name')
                    && property_exists($child, 'name')
                    && $this->operandsReferToSameVariable($child->name, $cfgCallOp->name)
                ) {
                    $sameCall = true;
                }
                if (!$sameCall) {
                    continue;
                }
            }
            $siteArg = $child->args[$argIndex] ?? null;
            if (null === $siteArg || !$this->operandsReferToSameVariable($siteArg, $callArg)) {
                continue;
            }
            $prev = $children[$i - 1] ?? null;
            break;
        }
        if (!$prev instanceof Op\Expr\Assign || !$this->operandsReferToSameVariable($prev->var, $callArg)) {
            return [];
        }
        $destSlot = $block->getVarSlot($prev->var, false);
        // List destruct assigns compile in the parent block; skip merge-block phi bind (#10807).
        if (!$this->blockHasAssignToSlot($block, (int) $destSlot)) {
            return [];
        }

        $rhsSlot = (int) $valueSlot;
        if ($rhsSlot === (int) $destSlot) {
            // `$path = 'a' . 'b'` — CONCAT already wrote into destSlot; self-sync would clobber (#16281).
            if ($this->assignAdjacentToBinaryExprProducer($block, $prev)) {
                return [];
            }
            $exprSlot = $block->slotForOperand($prev->expr);
            if (null !== $exprSlot && (int) $exprSlot !== (int) $destSlot) {
                $rhsSlot = (int) $exprSlot;
            } else {
                // Reassigned locals (e.g. $f = fopen after fclose($f)) — use latest ASSIGN RHS (#16271).
                foreach ($block->opCodes as $op) {
                    if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg2 === (int) $destSlot) {
                        $rhsSlot = (int) $op->arg3;
                    }
                }
            }
            if ($rhsSlot === (int) $destSlot) {
                return [];
            }
        }

        // `$a = ['k'=>1]; array_values($a)` — an identical dest←rhs ASSIGN already exists.
        // Emitting a second free()+store delrefs the HT and empties string-key walks under
        // thin AOT (#27545 / re-#27212). Peer: skip when CONCAT already wrote dest (#16281).
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_ASSIGN === $op->type
                && (int) $op->arg2 === (int) $destSlot
                && (int) $op->arg3 === (int) $rhsSlot
            ) {
                return [];
            }
        }

        return [new OpCode(
            OpCode::TYPE_ASSIGN,
            $this->compileOperand($prev->result, $block, false),
            $destSlot,
            $rhsSlot
        )];
    }

    /** `$x = 'a' . 'b'; f($x)` — CFG places BinaryOp immediately before Assign (#16281). */
    private function assignAdjacentToBinaryExprProducer(Block $block, Op\Expr\Assign $assign): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $assignIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $assign) {
                $assignIndex = $i;
                break;
            }
        }
        if (null === $assignIndex || $assignIndex < 1) {
            return false;
        }

        $prev = $block->orig->children[$assignIndex - 1];

        return $prev instanceof Op\Expr\BinaryOp || $prev instanceof Op\Expr\ConcatList;
    }

    /**
     * `$ini = "flag = $v"; parse_ini_string($ini)` inside loops — dest must not alias ConcatList temp (#18442).
     *
     * @param-out int $destSlot
     * @param-out int $rhsSlot
     */
    private function reconcileEncapsedConcatListAssignSlots(
        Op\Expr\Assign $assign,
        Block $block,
        int &$destSlot,
        int &$rhsSlot
    ): void {
        $concat = $this->concatListProducerFromAssignExpr($assign->expr);
        if (null === $concat || null === $concat->result) {
            return;
        }
        $producerSlot = $block->slotForOperand($concat->result);
        if (null === $producerSlot) {
            return;
        }
        $producerSlot = (int) $producerSlot;
        $rhsSlot = $producerSlot;
        if ((int) $destSlot === $producerSlot) {
            $name = Block::resolveVariableName($assign->var);
            $cvSlot = null !== $name ? $block->slotIndexForVariableName($name) : null;
            if (null === $cvSlot || (int) $cvSlot === $producerSlot) {
                $cvSlot = $block->forceFreshVarSlot($assign->var);
            }
            $destSlot = (int) $cvSlot;
        }
    }

    /** @return ?Op\Expr\ConcatList */
    private function concatListProducerFromAssignExpr(Operand $expr): ?Op\Expr\ConcatList
    {
        $unwrap = $expr;
        while ($unwrap instanceof Operand\Temporary && null !== $unwrap->original) {
            $unwrap = $unwrap->original;
        }
        if ($unwrap instanceof Op\Expr\ConcatList) {
            return $unwrap;
        }

        return $this->unwrapConcatListExpr($expr);
    }

    private function blockHasAssignToSlot(Block $block, int $destSlot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg2 === $destSlot) {
                return true;
            }
        }

        return false;
    }

    /** Function-local `static $x` binds the CV via DECLARE_FUNCTION_STATIC (#28038). */
    private function blockHasFunctionStaticDeclareToSlot(Block $block, int $destSlot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_FUNCTION_STATIC === $op->type && (int) $op->arg1 === $destSlot) {
                return true;
            }
        }

        return false;
    }

    /** Parent CFG blocks (list destruct merge) may hold the assign lowering (#10807). */
    private function blockHasAssignToSlotInParentBlocks(Block $block, int $destSlot, array $visited = []): bool
    {
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $id = spl_object_id($parent);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            if ($this->blockHasAssignToSlot($parent, $destSlot)) {
                return true;
            }
            if ($this->blockHasAssignToSlotInParentBlocks($parent, $destSlot, $visited)) {
                return true;
            }
        }

        return false;
    }

}
