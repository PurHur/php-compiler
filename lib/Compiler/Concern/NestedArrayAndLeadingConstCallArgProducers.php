<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Nested inline Array_ chains and leading ConstFetch call-arg preludes (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers outermost/nested Array_ producer matching for call args, ConstFetch +
 * Array_/FuncCall prelude splits, and leading ConstFetch FuncCall prelude slot
 * finalization used from compileCallArgSends.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait NestedArrayAndLeadingConstCallArgProducers
{
    /**
     * Sole hoisted arg #0 with nested inline Array_ preludes — wire outer root, not inner (#11300, #12008).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchOutermostNestedInlineArrayProducerForArgZero(
        array $producers,
        int $argIndex,
        int $argCount,
        int $producerCount
    ): ?Op\Expr\Array_ {
        if (0 !== $argIndex) {
            return null;
        }
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null !== $nestedTrailing) {
            [$arrayChain, $trailing] = $nestedTrailing;
            // new LimitIterator(new ArrayIterator([...]), …) — Array_ feeds inner ctor, not outer arg (#12916).
            if (($trailing[0] ?? null) instanceof Op\Expr\New_) {
                return null;
            }
            $outer = $arrayChain[\count($arrayChain) - 1] ?? null;

            return $outer instanceof Op\Expr\Array_ ? $outer : null;
        }
        $arrayProducers = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
        ));
        if (
            \count($arrayProducers) >= 2
            && $this->producersAreNestedArrayLiteralChain($arrayProducers)
            && $this->arrayProducersFormNestedChain($arrayProducers)
        ) {
            $outer = $arrayProducers[\count($arrayProducers) - 1];

            return $outer instanceof Op\Expr\Array_ ? $outer : null;
        }
        if (
            $argCount > $producerCount
            && $this->producersAreNestedArrayLiteralChain($producers)
            && $this->arrayProducersFormNestedChain($producers)
        ) {
            $outer = $producers[$producerCount - 1] ?? null;

            return $outer instanceof Op\Expr\Array_ ? $outer : null;
        }

        return null;
    }

    /**
     * php-cfg emits one Expr_Array producer per nesting level for inline literal args (#4738).
     *
     * @param list<Op\Expr> $producers
     */
    private function producersAreNestedArrayLiteralChain(array $producers): bool
    {
        if ([] === $producers) {
            return false;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\Array_) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when inline Array_ producers nest outer-wrapping-inner (#4738, #10848).
     *
     * @param list<Op\Expr> $producers
     */
    private function arrayProducersFormNestedChain(array $producers): bool
    {
        if (\count($producers) < 2) {
            return false;
        }
        for ($i = 1, $n = \count($producers); $i < $n; ++$i) {
            $inner = $producers[$i - 1];
            $outer = $producers[$i];
            if (!$inner instanceof Op\Expr\Array_ || !$outer instanceof Op\Expr\Array_) {
                return false;
            }
            $nested = false;
            foreach ($outer->values as $value) {
                if ($this->operandsReferToSameVariable($value, $inner->result)) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Operand> $callArgs
     */
    private function soleNonEmbeddedCallArgIndex(array $callArgs): ?int
    {
        $index = null;
        $count = 0;
        foreach ($callArgs as $i => $callArg) {
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            ++$count;
            $index = $i;
        }
        if (1 !== $count) {
            return null;
        }

        return $index;
    }

    /**
     * array_column([['n'=>'a'], …], 'n') — nested inline haystack preludes share one hoisted arg (#13703).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchSoleNestedInlineArrayHaystackProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr {
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
        if (null === $soleHoisted || $argIndex !== $soleHoisted) {
            return null;
        }
        $callArg = $callArgs[$soleHoisted] ?? null;
        if (!$callArg instanceof Operand || !$this->callArgOperandExpectsArrayProducer($callArg)) {
            return null;
        }
        if (!$this->producersAreNestedArrayLiteralChain($producers) || \count($producers) < 2) {
            return null;
        }
        $last = $producers[\count($producers) - 1] ?? null;
        if (!$last instanceof Op\Expr\Array_) {
            return null;
        }

        return $last;
    }

    /**
     * Nested inline Array_ preludes for one call arg plus trailing hoisted producers (#10566).
     *
     * e.g. count([1, [2, 3]], COUNT_RECURSIVE) — producers [inner Array_, outer Array_, ConstFetch].
     *
     * @param list<Op\Expr> $producers
     *
     * @return array{0: list<Op\Expr\Array_>, 1: list<Op\Expr>}|null
     */
    private function splitNestedArrayLiteralChainWithTrailingProducers(array $producers): ?array
    {
        $count = \count($producers);
        if ($count < 2) {
            return null;
        }
        $trailing = [];
        $i = $count - 1;
        while ($i >= 0 && !($producers[$i] instanceof Op\Expr\Array_)) {
            $trailing[] = $producers[$i];
            --$i;
        }
        if ([] === $trailing) {
            return null;
        }
        $trailing = array_reverse($trailing);
        $arrayChain = array_slice($producers, 0, $i + 1);
        if ([] === $arrayChain || !$this->producersAreNestedArrayLiteralChain($arrayChain)) {
            return null;
        }

        return [$arrayChain, $trailing];
    }

    /**
     * http_build_query([..], '', '&', PHP_QUERY_RFC3986) — nested Array_ chain + trailing ConstFetch (#15932, #12008).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchNestedArrayTrailingConstFetchCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr {
        $callArg = $callArgs[$argIndex] ?? null;
        if (
            !$this->callArgIsDeadInlineTemporary($callArg)
            || $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            return null;
        }
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null === $nestedTrailing) {
            return null;
        }
        [, $trailing] = $nestedTrailing;
        if ([] === $trailing) {
            return null;
        }
        $lastNonEmbedded = null;
        foreach ($callArgs as $i => $candidate) {
            if (!$this->isEmbeddedCallLiteralArg($candidate)) {
                $lastNonEmbedded = (int) $i;
            }
        }
        if (null === $lastNonEmbedded || $argIndex !== $lastNonEmbedded) {
            return null;
        }
        $trailingHoistedOrd = 0;
        foreach ($callArgs as $i => $candidate) {
            if ($i <= 0) {
                continue;
            }
            if (
                !$this->isEmbeddedCallLiteralArg($candidate)
                && $this->callArgIsDeadInlineTemporary($candidate)
            ) {
                ++$trailingHoistedOrd;
                if ($i === $argIndex) {
                    break;
                }
            }
        }
        if ($trailingHoistedOrd < 1) {
            return null;
        }
        $producer = $trailing[$trailingHoistedOrd - 1] ?? null;
        if ($producer instanceof Op\Expr\ConstFetch || $producer instanceof Op\Expr\ClassConstFetch) {
            return $producer;
        }

        return null;
    }

    /**
     * Leading nested inline Array_ chain for one call arg plus remaining hoisted producers (#12258).
     *
     * e.g. array_replace_recursive(['a' => ['b' => 1]], ['a' => null])
     * — producers [inner Array_, outer Array_, ConstFetch, Array_].
     *
     * @param list<Op\Expr> $producers
     *
     * @return array{0: list<Op\Expr\Array_>, 1: list<Op\Expr>}|null
     */
    private function splitLeadingNestedArrayLiteralChainWithRemainingProducers(array $producers): ?array
    {
        $count = \count($producers);
        if ($count < 2) {
            return null;
        }
        for ($end = $count - 2; $end >= 1; --$end) {
            $prefix = \array_slice($producers, 0, $end + 1);
            if (
                $this->producersAreNestedArrayLiteralChain($prefix)
                && $this->arrayProducersFormNestedChain($prefix)
            ) {
                return [$prefix, \array_slice($producers, $end + 1)];
            }
        }
        if ($producers[0] instanceof Op\Expr\Array_) {
            return [[$producers[0]], \array_slice($producers, 1)];
        }

        return null;
    }

    /**
     * @param list<Op\Expr> $remaining
     */
    private function countInlineCallArgProducersInRemaining(array $remaining): int
    {
        if ([] === $remaining) {
            return 0;
        }
        if (
            $this->producersAreNestedArrayLiteralChain($remaining)
            && $this->arrayProducersFormNestedChain($remaining)
        ) {
            return 1;
        }
        $count = 0;
        foreach ($remaining as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                ++$count;
            } elseif (!$producer instanceof Op\Expr\ConstFetch) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<Op\Expr> $remaining
     */
    private function inlineCallArgProducerAtRemainingIndex(array $remaining, int $trailingIndex): ?Op\Expr
    {
        if (
            $this->producersAreNestedArrayLiteralChain($remaining)
            && $this->arrayProducersFormNestedChain($remaining)
        ) {
            return 0 === $trailingIndex ? $remaining[\count($remaining) - 1] : null;
        }
        $seen = 0;
        foreach ($remaining as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                if ($seen === $trailingIndex) {
                    return $producer;
                }
                ++$seen;
            } elseif (!$producer instanceof Op\Expr\ConstFetch) {
                if ($seen === $trailingIndex) {
                    return $producer;
                }
                ++$seen;
            }
        }

        return null;
    }

    /**
     * array_replace_recursive(['a' => ['b' => 1]], ['a' => null]) — nested arg #0 + null overlay arg #1 (#12258, #16160).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchLeadingNestedInlineArrayMergeFamilyCallArgProducer(
        array $producers,
        int $argIndex,
        int $argCount
    ): ?Op\Expr {
        if ($argCount < 2) {
            return null;
        }
        $leadingNestedRemaining = $this->splitLeadingNestedArrayLiteralChainWithRemainingProducers($producers);
        if (null === $leadingNestedRemaining) {
            return null;
        }
        [$prefixChain, $remaining] = $leadingNestedRemaining;
        $trailingArgCount = $this->countInlineCallArgProducersInRemaining($remaining);
        if (1 + $trailingArgCount !== $argCount) {
            return null;
        }
        if (0 === $argIndex) {
            return $prefixChain[\count($prefixChain) - 1];
        }

        return $this->inlineCallArgProducerAtRemainingIndex($remaining, $argIndex - 1);
    }

    /**
     * ConstFetch prelude before nested inline Array_ call arg (#12007, filter_var + options array).
     *
     * e.g. filter_var('abc', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^a/']])
     * — producers [ConstFetch, inner Array_, outer Array_].
     *
     * Also: filter_var('01', FILTER_VALIDATE_INT, ['options' => ['flags' => FILTER_FLAG_ALLOW_OCTAL]])
     * — producers [ConstFetch filter, ConstFetch flags, inner Array_, outer Array_] (#22772).
     * Intervening ConstFetches are array-element values; the call arg is still the outermost Array_.
     *
     * @param list<Op\Expr> $producers
     *
     * @return array{0: Op\Expr\ConstFetch, 1: list<Op\Expr\Array_>}|null
     */
    private function splitLeadingConstFetchWithNestedArrayLiteralChain(array $producers): ?array
    {
        $count = \count($producers);
        if ($count < 2) {
            return null;
        }
        $first = $producers[0];
        if (!$first instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $arrayStart = null;
        for ($i = 0; $i < $count; ++$i) {
            if ($producers[$i] instanceof Op\Expr\Array_) {
                $arrayStart = $i;
                break;
            }
            if (!$producers[$i] instanceof Op\Expr\ConstFetch) {
                return null;
            }
        }
        if (null === $arrayStart || $arrayStart < 1) {
            return null;
        }
        $arrayChain = \array_slice($producers, $arrayStart);
        if ([] === $arrayChain || !$this->producersAreNestedArrayLiteralChain($arrayChain)) {
            return null;
        }
        if (!$this->arrayProducersFormNestedChain($arrayChain)) {
            return null;
        }

        return [$first, $arrayChain];
    }

    /**
     * ConstFetch prelude before single inline Array_ call arg (#12326, filter_var flags options).
     *
     * e.g. filter_var('not-int', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE])
     * — producers [ConstFetch filter, ConstFetch flags, Array_ options].
     *
     * @param list<Op\Expr> $producers
     *
     * @return array{0: Op\Expr\ConstFetch, 1: Op\Expr\Array_}|null
     */
    /**
     * FILTER_* names that are option bitmasks, not filter ids (php-src ext/filter/php_filter.h).
     */
    private function isFilterVarOptionFlagConstName(string $name): bool
    {
        return str_starts_with($name, 'filter_flag_')
            || \in_array($name, [
                'filter_null_on_failure',
                'filter_throw_on_failure',
                'filter_require_array',
                'filter_require_scalar',
                'filter_force_array',
            ], true);
    }

    private function splitLeadingConstFetchWithArrayLiteralCallArg(array $producers): ?array
    {
        $count = \count($producers);
        if ($count < 2) {
            return null;
        }
        $first = $producers[0];
        if (!$first instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $last = $producers[$count - 1];
        if (!$last instanceof Op\Expr\Array_) {
            return null;
        }
        $rest = \array_slice($producers, 1);
        if ($this->producersAreNestedArrayLiteralChain($rest) && $this->arrayProducersFormNestedChain($rest)) {
            return null;
        }
        for ($i = 1; $i < $count - 1; ++$i) {
            if (!$producers[$i] instanceof Op\Expr\ConstFetch) {
                return null;
            }
        }

        return [$first, $last];
    }

    /**
     * @param list<Op\Expr> $producers
     *
     * @return array{0: Op\Expr\ConstFetch, 1: Op\Expr\FuncCall|Op\Expr\NsFuncCall}|null
     */
    private function splitLeadingConstFetchWithFuncCallCallArg(array $producers): ?array
    {
        if (2 !== \count($producers)) {
            return null;
        }
        $first = $producers[0];
        $second = $producers[1];
        if (!$first instanceof Op\Expr\ConstFetch) {
            return null;
        }
        if (!$second instanceof Op\Expr\FuncCall && !$second instanceof Op\Expr\NsFuncCall) {
            return null;
        }

        return [$first, $second];
    }

    /**
     * @param list<Op\Expr> $producers
     */
    private function producersAreSiblingCallWithHoistedScalarConstFetch(array $producers): bool
    {
        if (2 !== \count($producers)) {
            return false;
        }
        $call = null;
        $scalarConst = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall
                || $producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall
                || $producer instanceof Op\Expr\Include_ || $producer instanceof Op\Expr\Eval_) {
                $call = $producer;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($producer->name);
                if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    $scalarConst = $producer;
                }
            }
        }

        return null !== $call && null !== $scalarConst;
    }

    /**
     * @param list<Op\Expr> $producers
     */
    private function producersAreSiblingArithmeticWithHoistedScalarConstFetch(array $producers): bool
    {
        if (2 !== \count($producers)) {
            return false;
        }
        $arith = null;
        $scalarConst = null;
        foreach ($producers as $producer) {
            if ($this->isChainedArithmeticBinaryOpExpr($producer)) {
                $arith = $producer;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($producer->name);
                if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    $scalarConst = $producer;
                }
            }
        }

        return null !== $arith && null !== $scalarConst;
    }

    /**
     * @param list<Op\Expr> $producers
     */
    private function producersAreSiblingCallWithHoistedEnumConstFetch(array $producers): bool
    {
        if (2 !== \count($producers)) {
            return false;
        }
        $call = null;
        $enumFetch = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                $call = $producer;
            } elseif ($producer instanceof Op\Expr\ClassConstFetch) {
                $enumFetch = $producer;
            }
        }

        return null !== $call && null !== $enumFetch;
    }

    /**
     * fseek($stream, -N, SEEK_*) — hoisted UnaryMinus offset + ConstFetch whence preludes (#16523).
     *
     * @param list<Op\Expr> $producers
     */
    private function producersIncludeUnaryOffsetWithConstWhence(array $producers): bool
    {
        $hasUnary = false;
        $hasConst = false;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                $hasUnary = true;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $hasConst = true;
            }
        }

        return $hasUnary && $hasConst;
    }

    /**
     * @return array{0: Op\Expr\ConstFetch, 1: Op\Expr\FuncCall|Op\Expr\NsFuncCall}|null
     */
    private function leadingConstFetchFuncCallPreludeBeforeCfgCall(Op $callOp, Block $block): ?array
    {
        if (null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null === $callIndex || $callIndex < 2) {
            return null;
        }
        $func = $block->orig->children[$callIndex - 1] ?? null;
        $const = $block->orig->children[$callIndex - 2] ?? null;
        if (!$const instanceof Op\Expr\ConstFetch) {
            return null;
        }
        if (!$func instanceof Op\Expr\FuncCall && !$func instanceof Op\Expr\NsFuncCall) {
            return null;
        }
        // probe('label', nested_call()) — ConstFetch+FuncCall preludes belong to nested call (#15846).
        // str_contains(print_r(..., true), 'zzz') — trailing outer literal; ConstFetch feeds nested (#24372).
        if (
            property_exists($callOp, 'args')
            && \is_array($callOp->args)
        ) {
            if (
                isset($callOp->args[0])
                && $this->isEmbeddedCallLiteralArg($callOp->args[0])
            ) {
                return null;
            }
            foreach ($callOp->args as $outerArg) {
                if ($this->isEmbeddedCallLiteralArg($outerArg)) {
                    if (
                        $this->hoistedConstFetchFeedsNestedSiblingFuncCallArg(
                            $const,
                            $callIndex - 2,
                            $callIndex,
                            $block->orig->children
                        )
                    ) {
                        return null;
                    }
                    break;
                }
            }
        }

        return [$const, $func];
    }

    /**
     * explode(PATH_SEPARATOR, get_include_path()) — final ARG_SEND must bind ConstFetch + sibling FuncCall (#15833).
     *
     * Not for `str_contains(print_r(..., true), 'zzz')`: the ConstFetch feeds the *nested* callee, and the
     * outer needle is an embedded literal — remapping arg #1 onto the nested EXEC_RETURN made needles
     * alias the haystack (#24372).
     *
     * @param list<OpCode> $emitOps
     */
    private function finalizeLeadingConstFetchFuncCallPreludeCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?string {
        if (
            null === $block->orig
            || !\is_array($cfgCallOp->args ?? null)
            || 2 !== \count($cfgCallOp->args)
            || 'array_slice' === $this->resolveCfgFuncCallName($cfgCallOp)
            || 'array_combine' === $this->resolveCfgFuncCallName($cfgCallOp)
            || (
                isset($cfgCallOp->args[0])
                && $this->isEmbeddedCallLiteralArg($cfgCallOp->args[0])
            )
            || $this->isEmbeddedCallLiteralArg($cfgCallOp->args[$argIndex] ?? null)
            || $this->consumerImmediateUnaryHoistedDeadTempArgZero($cfgCallOp, $block)
        ) {
            return null;
        }
        $constFuncPrelude = $this->leadingConstFetchFuncCallPreludeBeforeCfgCall($cfgCallOp, $block)
            ?? $this->splitLeadingConstFetchWithFuncCallCallArg(
                $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
            );
        if (null === $constFuncPrelude) {
            return null;
        }
        [$constFetch, $funcProducer] = $constFuncPrelude;
        // ConstFetch true/false/null before nested print_r/var_export feeds that callee, not outer args (#24372).
        if ($constFetch instanceof Op\Expr\ConstFetch) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            $constIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $constFetch, $block->orig);
            if (
                \is_int($callIndex)
                && \is_int($constIndex)
                && $this->hoistedConstFetchFeedsNestedSiblingFuncCallArg(
                    $constFetch,
                    $constIndex,
                    $callIndex,
                    $block->orig->children
                )
            ) {
                return null;
            }
        }
        $target = match ($argIndex) {
            0 => $constFetch,
            1 => $funcProducer,
            default => null,
        };
        if (!$target instanceof Op\Expr) {
            return null;
        }
        if (null === $block->slotForOperand($target->result)) {
            foreach ($this->compileExpr($target, $block) as $op) {
                $emitOps[] = $op;
            }
        }
        $splitSlot = $block->slotForOperand($target->result);

        return null !== $splitSlot ? (string) $splitSlot : null;
    }

    /**
     * explode(PATH_SEPARATOR, get_include_path()) — defer leading ConstFetch until consumer (#15833).
     *
     * @param list<Op> $ops
     */
    private function isDeferredLeadingConstFetchBeforeSiblingFuncCallConsumer(
        Op\Expr\ConstFetch $fetch,
        array $ops,
        int $fetchIndex
    ): bool {
        $func = $ops[$fetchIndex + 1] ?? null;
        $consumer = $ops[$fetchIndex + 2] ?? null;
        if (
            !($func instanceof Op\Expr\FuncCall || $func instanceof Op\Expr\NsFuncCall)
            || !($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            || !property_exists($consumer, 'args')
            || !\is_array($consumer->args)
            || 2 !== \count($consumer->args)
        ) {
            return false;
        }
        if (null === $this->splitLeadingConstFetchWithFuncCallCallArg([$fetch, $func])) {
            return false;
        }

        return 'explode' === $this->resolveCfgFuncCallName($consumer);
    }

    /**
     * round(...); fmod(-1.5, …) — immediate UnaryMinus/Plus feeds arg #0 (#13508, #15736).
     */
    private function consumerImmediateUnaryHoistedDeadTempArgZero(?Op $cfgCallOp, Block $block): bool
    {
        if (null === $cfgCallOp || null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return false;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $deadHoisted = 0;
        foreach ($cfgCallOp->args as $hoistedArg) {
            if ($this->callArgIsDeadInlineTemporary($hoistedArg)) {
                ++$deadHoisted;
            }
        }
        if (1 !== $deadHoisted) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return false;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;

        return $immediate instanceof Op\Expr\UnaryMinus || $immediate instanceof Op\Expr\UnaryPlus;
    }

    /**
     * Hoisted ConstFetch before a nested sibling FuncCall — feeds callee arg, not the consumer (#11272).
     *
     * @param list<Op> $cfgChildren
     */
    private function hoistedConstFetchFeedsNestedSiblingFuncCallArg(
        Op\Expr\ConstFetch $fetch,
        int $fetchIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (null === $fetch->result) {
            return false;
        }
        for ($j = $fetchIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($mid instanceof Op\Expr\FuncCall || $mid instanceof Op\Expr\NsFuncCall) {
                if (!property_exists($mid, 'args') || !is_array($mid->args)) {
                    return false;
                }
                $name = $this->staticNameFromOperand($fetch->name);
                if (null === $name || !\in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    return false;
                }
                foreach ($mid->args as $callArg) {
                    if (null === $callArg) {
                        continue;
                    }
                    if ($this->operandsReferToSameVariable($fetch->result, $callArg)) {
                        return true;
                    }
                    if ($this->callArgIsDeadInlineTemporary($callArg)) {
                        return true;
                    }
                }

                return false;
            }

            return false;
        }

        return false;
    }
}
