<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;

/**
 * Inline call-arg producer slot discovery (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Array_ producer discovery lives in {@see FindInlineArrayProducerForCallArg}.
 * Inline expr producer discovery lives in {@see FindInlineExprCallArgProducerSlot}.
 * Coalesce / nullsafe call-arg slots live in {@see FindInlineCoalesceAndNullsafeCallArgSlots}.
 * Remaining: dead-temp / array-producer helper slots.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as CompileCallArgSends).
 */
trait FindInlineCallArgProducerSlot
{
    /**
     * php-cfg dead call-arg temps for named parameters — map to hoisted Array_ not assigned to a named local (#11170).
     *
     * @param list<Op\Expr> $producers
     */
    private function findUnassignedInlineArrayProducerForDeadCallArg(
        array $producers,
        Op $callOp,
        int $argIndex,
        Block $block
    ): ?Op\Expr {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $deadArrayArgIndices = [];
        foreach ($callOp->args as $idx => $callArg) {
            if (!$this->callArgIsDeadInlineTemporary($callArg) || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if ($this->callArgOperandExpectsArrayProducer($callArg)) {
                $deadArrayArgIndices[] = (int) $idx;
            }
        }
        $positionAmongDeadArrays = array_search($argIndex, $deadArrayArgIndices, true);
        if (false === $positionAmongDeadArrays) {
            return null;
        }
        $unassigned = [];
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->inlineProducerAssignedToNamedLocalBeforeCall($producer, $callOp, $block)) {
                continue;
            }
            $unassigned[] = $producer;
        }
        if ([] === $unassigned) {
            return null;
        }
        if (1 === \count($deadArrayArgIndices)) {
            $immediate = $this->inlineArrayProducerImmediatelyBeforeCfgCall($callOp, $block);
            if ($immediate instanceof Op\Expr\Array_) {
                return $immediate;
            }

            return $unassigned[\count($unassigned) - 1];
        }
        // Nested / sibling inline Array_ chains — flat unassigned[] index is wrong (#12729, #12730).
        $matched = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
        if (
            $matched instanceof Op\Expr\FuncCall
            || $matched instanceof Op\Expr\NsFuncCall
            || $matched instanceof Op\Expr\StaticCall
            || $matched instanceof Op\Expr\MethodCall
            || $matched instanceof Op\Expr\Cast
        ) {
            return $matched;
        }
        if ($this->inlineCallArgProducerUsesExprResultSlot($matched)) {
            return $matched;
        }

        return $unassigned[$positionAmongDeadArrays] ?? null;
    }

    /** Hoisted inline call-arg producers whose Expr::result is the callee operand (#10490, #12763). */
    private function inlineCallArgProducerUsesExprResultSlot(?Op\Expr $matched): bool
    {
        return $matched instanceof Op\Expr\Cast
            || $matched instanceof Op\Expr\BooleanNot
            || $matched instanceof Op\Expr\BitwiseNot
            || $matched instanceof Op\Expr\Array_
            || $matched instanceof Op\Expr\BinaryOp\Plus
            || $matched instanceof Op\Expr\BinaryOp\Concat
            || $this->isComparisonInlineCallArgProducer($matched);
    }

    /**
     * Hoisted Array_ preludes followed by Plus — inline array union call arg (#10490, #13787).
     *
     * @param list<Op\Expr> $producers
     */
    private function producersIncludeInlineArrayUnionPlus(array $producers): bool
    {
        $seenArray = false;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $seenArray = true;
            } elseif ($seenArray && $producer instanceof Op\Expr\BinaryOp\Plus) {
                return true;
            }
        }

        return false;
    }

    /** True when hoisted producers before $callOp include array union / concat (#12763, re-#10578). */
    private function precedingInlineCallArgHasPlusOrConcatProducer(array $cfgChildren, Op $callOp): bool
    {
        foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $callOp) as $producer) {
            if ($producer instanceof Op\Expr\BinaryOp\Plus
                || $producer instanceof Op\Expr\BinaryOp\Concat
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dead php-cfg call-arg temps that expect hoisted Array_ producers (#11586, #12730).
     *
     * @param Op\Expr\FuncCall|Op\Expr\NsFuncCall|Op $callOp
     */
    private function countDeadArrayInlineCallArgs(Op $callOp): int
    {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return 0;
        }
        $count = 0;
        foreach ($callOp->args as $callArg) {
            if (!$this->callArgIsDeadInlineTemporary($callArg) || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if ($this->callArgOperandExpectsArrayProducer($callArg)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Named `command: [...]` style dead temps — last hoisted Array_ between call and prior Assign (#11170).
     */
    private function resolveNamedDeadTempArrayCallArgSlot(Op $callOp, Block $block): ?string
    {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return null;
        }
        $lastArray = null;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\Assign || $child instanceof Op\Expr\AssignRef) {
                break;
            }
            if ($child instanceof Op\Expr\Array_) {
                $lastArray = $child;
            }
        }
        if (null === $lastArray) {
            return null;
        }
        if (null === $block->slotForOperand($lastArray->result)) {
            foreach ($this->compileExpr($lastArray, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($lastArray->result);

        return null !== $slot ? (string) $slot : null;
    }

    private function inlineProducerAssignedToNamedLocalBeforeCall(
        Op\Expr $producer,
        Op $callOp,
        Block $block
    ): bool {
        if (null === $block->orig || null === $producer->result) {
            return false;
        }
        $children = $block->orig->children;
        $producerIndex = null;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $producer) {
                $producerIndex = $i;
            }
            if ($child === $callOp) {
                $callIndex = $i;
            }
        }
        if (null === $producerIndex || null === $callIndex || $producerIndex >= $callIndex) {
            return false;
        }
        for ($i = $producerIndex + 1; $i < $callIndex; ++$i) {
            $stmt = $children[$i];
            if (
                $stmt instanceof Op\Expr\Assign
                && $this->operandsReferToSameVariable($stmt->expr, $producer->result)
                && $this->isNamedVariableOperand($stmt->var)
            ) {
                return true;
            }
        }

        return false;
    }

    /** Dead php-cfg call-arg temp whose inferred type is array-shaped (incl. `string[]`, #11170). */
    private function callArgOperandExpectsArrayProducer(Operand $callArg): bool
    {
        $root = $this->unwrapOperandChain($callArg);
        if (null === $root->type || !method_exists($root->type, 'toString')) {
            return false;
        }
        $repr = $root->type->toString();
        if ('array' === $repr) {
            return true;
        }
        if (str_ends_with($repr, '[]')) {
            return true;
        }
        // Union/intersection builtins (proc_open array|string, etc.) may pass inline Expr_Array (#13734).
        return (bool) preg_match('/\barray\b/', $repr);
    }

    /**
     * Dead ctor arg with unknown/mixed (or absent) type — #22390 `new C([...])` defaults.
     * Typed null/int/… must not use the stmt-before Array_ fallback (#22770).
     */
    private function callArgIsDeadUnknownOrMixedTemporary(Operand $callArg): bool
    {
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $root = $this->unwrapOperandChain($callArg);
        if (null === $root->type || !method_exists($root->type, 'toString')) {
            return true;
        }
        $repr = $root->type->toString();

        return \in_array($repr, ['unknown', 'mixed'], true);
    }

    /**
     * Whether a New_ dead arg may bind the immediate stmt-before Array_ (#22390).
     * Rejects typed non-array temps and args whose multi-arg producer match is not Array_ (#22770).
     */
    private function newCtorDeadTempMayBindStmtBeforeArray(
        Operand $callArg,
        Op $callOp,
        int $argIndex,
        Block $block
    ): bool {
        if (!$callOp instanceof Op\Expr\New_ || !$this->callArgIsDeadUnknownOrMixedTemporary($callArg)) {
            return false;
        }
        if (null === $block->orig || !\is_array($callOp->args) || \count($callOp->args) < 2) {
            return true;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        if (\count($producers) < 2) {
            return true;
        }
        $matched = $this->matchInlineCallArgProducer(
            $producers,
            $callOp->args,
            $argIndex,
            $callOp,
            $block
        );

        return !$matched instanceof Op\Expr || $matched instanceof Op\Expr\Array_;
    }

    /**
     * Dead call-arg temp whose php-cfg type is unknown/mixed, fed by stmt-before Array_ of
     * ClassConstFetch values (enum cases). Typed int[]/string[] arrays already hit
     * {@see callArgOperandExpectsArrayProducer()}; enum-case arrays stay inferred:unknown so
     * ARG_SEND would otherwise steal the first ClassConstFetch (#19786).
     */
    private function callArgIsDeadUntypedInlineArrayOfClassConst(
        Operand $callArg,
        Op $cfgCallOp,
        Block $block,
        int $argIndex
    ): bool {
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        if ($this->callArgOperandExpectsArrayProducer($callArg)) {
            return false;
        }
        $root = $this->unwrapOperandChain($callArg);
        if (null !== $root->type && method_exists($root->type, 'toString')) {
            $repr = $root->type->toString();
            if (!\in_array($repr, ['unknown', 'mixed'], true)) {
                return false;
            }
        }
        // Prefer the stmt-before Array_; with sibling flat literals the ClassConstFetch array
        // may be earlier (call_user_func_array([A::class, 'm'], [...]) — #27139).
        $array = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
        if (
            (!$array instanceof Op\Expr\Array_ || !$this->arrayLiteralHasClassConstFetchValue($array))
            && null !== $block->orig
        ) {
            $array = null;
            foreach ($this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            ) as $producer) {
                if (
                    $producer instanceof Op\Expr\Array_
                    && $this->arrayLiteralHasClassConstFetchValue($producer)
                ) {
                    $array = $producer;
                    break;
                }
            }
        }
        if (!$array instanceof Op\Expr\Array_ || !$this->arrayLiteralHasClassConstFetchValue($array)) {
            return false;
        }
        $argc = \count($cfgCallOp->args ?? []);
        if (1 === $argc) {
            return 0 === $argIndex;
        }
        if (null === $block->orig) {
            return false;
        }
        // Multi-arg: ClassConstFetch mapped to this arg is an Array_ element ⇒ arg is the Array_
        // (json_encode([E::A]); f([E::A], E::B) arg0). Bare E::A arg keeps ClassConstFetch wiring.
        // Trailing-enum ordinal fallback can also map that same array-element fetch onto a later
        // dead ConstFetch temp (http_build_query([E::A], '', '&', PHP_QUERY_RFC3986) — #23702);
        // only arg #0 is the enum-case array in that case.
        $fetches = $this->precedingCallArgClassConstFetchesBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp,
            $block
        );
        $fetch = $this->precedingClassConstFetchForCallArgIndex($cfgCallOp, $argIndex, $fetches);
        if (!$fetch instanceof Op\Expr\ClassConstFetch || null === $fetch->result) {
            return 0 === $argIndex;
        }
        foreach ($array->values as $value) {
            if (null !== $value && $this->operandsReferToSameVariable($value, $fetch->result)) {
                return 0 === $argIndex;
            }
        }

        return false;
    }

    /** @param Op\Expr\Array_ $array */
    private function arrayLiteralHasClassConstFetchValue(Op\Expr\Array_ $array): bool
    {
        foreach ($array->values as $value) {
            if (null === $value) {
                continue;
            }
            $candidates = [$value, $this->unwrapOperandChain($value)];
            foreach ($candidates as $operand) {
                foreach ($operand->ops ?? [] as $op) {
                    if ($op instanceof Op\Expr\ClassConstFetch) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Dedicated array-producer ARG_SEND resolution — haystack-family builtins only (#15612, #14134).
     *
     * Skips mb_detect_order([...]) and other mutators that take a single inline array operand.
     * Multi-array set ops (array_diff/intersect/merge) wire every hoisted array operand (#15947).
     */
    private function shouldUseArrayProducerCallArgResolution(?Op $cfgCallOp, int $argIndex, ?string $calleeName): bool
    {
        if (null === $cfgCallOp || $argIndex < 0) {
            return false;
        }
        $callee = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');

        if (\in_array($callee, [
            'in_array',
            'array_search',
            'array_key_exists',
            'key_exists',
            'array_column',
        ], true)) {
            return $argIndex >= 1;
        }

        return \in_array($callee, [
            'array_combine',
            'array_merge',
            'array_merge_recursive',
            'array_replace',
            'array_replace_recursive',
            'array_diff',
            'array_intersect',
            'array_diff_key',
            'array_intersect_key',
            'array_intersect_assoc',
            'array_diff_assoc',
            'array_udiff',
            'array_uintersect',
            'array_udiff_assoc',
            'array_uintersect_assoc',
            'array_udiff_uassoc',
            'array_uintersect_uassoc',
            'array_diff_uassoc',
            'array_intersect_uassoc',
            'array_diff_ukey',
            'array_intersect_ukey',
        ], true);
    }

    /** Dead array temp that needs haystack-family producer wiring (#15612), not embedded literal (#14134). */
    private function callArgUsesHaystackFamilyArrayProducerResolution(
        ?Op $cfgCallOp,
        int $argIndex,
        ?string $calleeName,
        Operand $arg
    ): bool {
        if ($this->callArgUnpack($arg)) {
            return false;
        }

        return $this->callArgIsDeadInlineTemporary($arg)
            && $this->callArgOperandExpectsArrayProducer($arg)
            && $this->shouldUseArrayProducerCallArgResolution($cfgCallOp, $argIndex, $calleeName);
    }

    /** Haystack-family dead inline temp — prefer dim-fetch / array producer slots over echo concat (#17000). */
    private function callArgIsDeadInlineHaystackFamilySlot(
        ?Op $cfgCallOp,
        int $argIndex,
        ?string $calleeName,
        Operand $arg
    ): bool {
        if ($this->callArgUnpack($arg)) {
            return false;
        }

        return null !== $cfgCallOp
            && $this->shouldUseArrayProducerCallArgResolution($cfgCallOp, $argIndex, $calleeName)
            && $this->callArgIsDeadInlineTemporary($arg);
    }

}
