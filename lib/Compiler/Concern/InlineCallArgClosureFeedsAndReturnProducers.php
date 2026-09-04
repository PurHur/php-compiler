<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCompiler\OpCode;
use PHPCfg\Block as CfgBlock;
use PHPTypes\Type;

/**
 * Inline call-arg closure/array_reduce feeds and return-slot producers (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers var_export return-true detection, single-inline-closure callback matching,
 * array_reduce initial/array producers, call-result→call-arg feeding, void-return
 * php-cfg artifacts, and INIT_ARRAY slot helpers used from compileCallArgSends /
 * InlineCallArgProducerMatch. CFG producer index/rematerialize lives in
 * {@see CfgProducerIndexAndRematerialize}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait InlineCallArgClosureFeedsAndReturnProducers
{
    /** `var_export($x, true)` returns a string instead of echoing (#10704). */
    private function isVarExportReturnTrueCall(?Op $cfgCallOp, Block $block): bool
    {
        if (
            !$cfgCallOp instanceof Op\Expr\FuncCall
            && !$cfgCallOp instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        $name = $this->resolveCfgFuncCallName($cfgCallOp);
        if ('var_export' !== $name) {
            return false;
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }

        return $this->cfgOperandIsTrue($cfgCallOp->args[1] ?? null, $block);
    }

    /**
     * var_export($arr['k']->prop, true) — hoisted true after PropertyFetch must compile eagerly;
     * deferral to compileCallArgSends loses the return-flag slot under AOT (#31938).
     *
     * @param Op[] $ops
     */
    private function isVarExportReturnFlagAfterPropertyFetchPrelude(
        Op\Expr $fetch,
        array $ops,
        int $fetchIndex
    ): bool {
        if (!$fetch instanceof Op\Expr\ConstFetch) {
            return false;
        }
        $flagName = strtolower($this->staticNameFromOperand($fetch->name) ?? '');
        if (!\in_array($flagName, ['true', 'false'], true)) {
            return false;
        }
        $consumer = $ops[$fetchIndex + 1] ?? null;
        if (!$consumer instanceof Op\Expr\FuncCall && !$consumer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if ('var_export' !== strtolower($this->resolveCfgFuncCallName($consumer) ?? '')) {
            return false;
        }
        $prev = $ops[$fetchIndex - 1] ?? null;

        return $prev instanceof Op\Expr\PropertyFetch
            || $prev instanceof Op\Expr\NullsafePropertyFetch
            || $prev instanceof Op\Expr\StaticPropertyFetch;
    }

    private function cfgOperandIsTrue(?Operand $operand, Block $block): bool
    {
        if ($operand instanceof Operand\Literal) {
            return true === $operand->value;
        }
        if (null === $operand || null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if ($child->result !== $operand) {
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch && $child->name instanceof Operand\Literal) {
                return 'true' === strtolower((string) $child->name->value);
            }
        }
        $root = $operand;
        while ($root instanceof Temporary && null !== $root->original) {
            $root = $root->original;
        }
        if ($root instanceof Operand\Literal) {
            return true === $root->value;
        }

        return false;
    }

    private function resolveCfgFuncCallName(?Op $call): ?string
    {
        if (!$call instanceof Op\Expr) {
            return null;
        }
        $cacheKey = spl_object_id($call);
        if (\array_key_exists($cacheKey, $this->resolveCfgFuncCallNameCache)) {
            return $this->resolveCfgFuncCallNameCache[$cacheKey];
        }
        $result = null;
        if ($call instanceof Op\Expr\FuncCall && $call->name instanceof Operand\Literal) {
            $result = strtolower((string) $call->name->value);
        } elseif ($call instanceof Op\Expr\NsFuncCall && $call->name instanceof Operand\Literal) {
            $result = strtolower((string) $call->name->value);
        } elseif ($call instanceof Op\Expr\MethodCall && $call->name instanceof Operand\Literal) {
            $result = strtolower((string) $call->name->value);
        }
        $this->resolveCfgFuncCallNameCache[$cacheKey] = $result;

        return $result;
    }

    /** Folded callee hint for variable calls ($fn = 'array_all'; $fn(...), #12766). */
    private function resolveInlineCallArgFuncName(?Op $call, ?string $calleeName = null): ?string
    {
        $resolved = $this->resolveCfgFuncCallName($call);
        if (null !== $resolved) {
            return $resolved;
        }
        if (null === $calleeName || '' === $calleeName) {
            return null;
        }

        return strtolower($calleeName);
    }

    /**
     * Zend handler builtins whose sole argument may be an inline Closure/ArrowFunction (#17846, #17845).
     *
     * touch(); ob_start(fn(...)) must not treat touch as a hoisted sibling arg producer.
     */
    private function builtinAcceptsSingleInlineClosureCallback(?string $funcName, int $argCount = 1): bool
    {
        if (null === $funcName || '' === $funcName || 1 !== $argCount) {
            return false;
        }

        return \in_array(strtolower($funcName), [
            'ob_start',
            'set_error_handler',
            'set_exception_handler',
            'register_shutdown_function',
            'register_tick_function',
            'unregister_tick_function',
            'header_register_callback',
            'spl_autoload_register',
        ], true);
    }

    private function cfgCallAcceptsSingleInlineClosureCallback(Op $callOp): bool
    {
        if (!$callOp instanceof Op\Expr\FuncCall && !$callOp instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!\is_array($callOp->args ?? null)) {
            return false;
        }

        return $this->builtinAcceptsSingleInlineClosureCallback(
            $this->resolveCfgFuncCallName($callOp),
            \count($callOp->args)
        );
    }

    /**
     * array_reduce([...], fn(...), [...]) — two+ inline Array_ producers before the call (#5626).
     */
    private function arrayReduceCfgCallHasMultipleInlineArrayProducers(Block $block, Op $cfgCallOp): bool
    {
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren && null !== $block->orig) {
            $cfgChildren = $block->orig->children;
        }
        if ([] === $cfgChildren) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (null === $callIndex) {
            return false;
        }
        $arrayCount = 0;
        for ($i = 0; $i < $callIndex; ++$i) {
            if (($cfgChildren[$i] ?? null) instanceof Op\Expr\Array_) {
                ++$arrayCount;
                if ($arrayCount >= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * array_reduce([...], fn(...), [...]) — wire input Array_ chain, closure, initial Array_ (#5626).
     *
     * Skips ClassConstFetch/ConstFetch preludes (enum case elements) before the first Array_.
     *
     * @param list<Op\Expr> $producers
     */
    private function matchArrayReduceInlineArrayClosureInitialProducer(
        array $producers,
        int $argIndex,
        int $closureProducerIndex
    ): ?Op\Expr {
        $arrayCount = 0;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                ++$arrayCount;
            }
        }
        if ($arrayCount < 2) {
            return null;
        }
        $firstArrayPi = null;
        foreach ($producers as $pi => $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $firstArrayPi = $pi;
                break;
            }
        }
        if (null === $firstArrayPi) {
            return null;
        }
        $fromFirstArray = \array_slice($producers, $firstArrayPi);
        $leading = $this->splitLeadingNestedArrayLiteralChainWithRemainingProducers($fromFirstArray);
        if (null === $leading) {
            return null;
        }
        [$chain, $remaining] = $leading;
        if ([] === $chain) {
            return null;
        }
        $inputArray = $chain[\count($chain) - 1];
        if (!$inputArray instanceof Op\Expr\Array_) {
            return null;
        }
        $initialArray = null;
        foreach ($remaining as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $initialArray = $producer;
            }
        }
        if (null === $initialArray || $initialArray === $inputArray) {
            return null;
        }
        if (0 === $argIndex) {
            return $inputArray;
        }
        if (1 === $argIndex) {
            return $producers[$closureProducerIndex];
        }
        if (2 === $argIndex) {
            return $initialArray;
        }

        return null;
    }

    /** Callback arg index for closure + inline Array_ hoists (array_map vs array_reduce, #10775). */
    private function inlineClosureArrayPairCallbackArgIndex(?string $funcName): int
    {
        if (null === $funcName || '' === $funcName) {
            return -1;
        }
        if (in_array($funcName, [
            'array_all',
            'array_any',
            'array_find',
            'array_find_key',
            'array_reduce',
            'array_walk',
            'array_walk_recursive',
            'array_filter',
            'iterator_apply',
        ], true)) {
            return 1;
        }
        if (in_array($funcName, [
            'array_map',
            'register_shutdown_function',
            'ob_start',
            'set_error_handler',
            'set_exception_handler',
            'register_tick_function',
            'unregister_tick_function',
            'header_register_callback',
            'spl_autoload_register',
        ], true)) {
            return 0;
        }

        return -1;
    }

    /** php-cfg dead temps: inline FuncCall/New_/Array_ producer before a call (#8561, #4633). */
    private function callResultFeedsInlineCallArg(Operand $result, Block $block): bool
    {
        $cacheKey = spl_object_id($result);
        if (\array_key_exists($cacheKey, $this->callResultFeedsInlineCallArgCache)) {
            return $this->callResultFeedsInlineCallArgCache[$cacheKey];
        }
        $answer = $this->computeCallResultFeedsInlineCallArg($result, $block);
        $this->callResultFeedsInlineCallArgCache[$cacheKey] = $answer;

        return $answer;
    }

    /**
     * Whether $result is consumed as a hoisted inline call arg (empty php-cfg usages).
     *
     * Scan only a near window after the producer: walking every later FuncCall in the
     * block made nested call stmts O(n²) via matchInlineCallArgProducer (#36387).
     */
    private function computeCallResultFeedsInlineCallArg(Operand $result, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $children = $block->orig->children;
        $startIndex = 0;
        $producer = $this->findCfgProducerExprForOperand($result);
        if ($producer instanceof Op) {
            $producerIndex = $this->cfgCallOpIndexInChildren($children, $producer, $block->orig);
            if (null !== $producerIndex) {
                $startIndex = $producerIndex + 1;
            }
        }
        $n = \count($children);
        // Multi-arg nests with ConstFetch/Array_ preludes stay within a small span; 32
        // matches deferredSiblingInlineCallArgConsumerIndex's hard cap (#36387).
        $scanEnd = null !== $producer && $startIndex > 0
            ? min($n, $startIndex + 32)
            : $n;
        for ($i = $startIndex; $i < $scanEnd; ++$i) {
            $child = $children[$i];
            if (!$this->isInlineExprCallArgConsumer($child)) {
                continue;
            }
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($children, $child);
            foreach ($producers as $producerCand) {
                if ($producerCand->result === $result || $this->operandsReferToSameVariable($producerCand->result, $result)) {
                    if ($this->inlineCallArgProducerPassesByRefGuards($producerCand, $child, $children)) {
                        return true;
                    }
                }
            }
            // php-cfg distinct result/arg temps for multi-arg consumers (#9351).
            if (!property_exists($child, 'args') || !is_array($child->args)) {
                continue;
            }
            foreach ($child->args as $argIndex => $callArg) {
                $matched = $this->matchInlineCallArgProducer($producers, $child->args, (int) $argIndex, $child);
                if (!$matched instanceof Op\Expr) {
                    continue;
                }
                if (
                    $matched->result !== $result
                    && !$this->operandsReferToSameVariable($matched->result, $result)
                ) {
                    continue;
                }
                if ($this->inlineCallArgProducerPassesByRefGuards($matched, $child, $children)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<Op>|null $cfgChildren
     */
    private function inlineCallArgProducerPassesByRefGuards(Op\Expr $producer, Op $consumer, ?array $cfgChildren = null): bool
    {
        if (
            !($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            || !property_exists($consumer, 'args')
            || !is_array($consumer->args)
        ) {
            return true;
        }
        $feedsConsumerArg = false;
        foreach ($consumer->args as $consumerArg) {
            if (!$this->inlineCallArgProducerFeedsCallArgOp($producer, $consumer, $consumerArg)) {
                continue;
            }
            $feedsConsumerArg = true;
            if ($this->funcCallExprByRefArgMatchesOperand($producer, $consumerArg)) {
                return false;
            }
            if (!$this->namedCallArgMayUseFuncCallProducerResult($producer, $consumerArg)) {
                return false;
            }
        }
        if (!$feedsConsumerArg && null !== $cfgChildren) {
            $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $producer);
            $consumerIndex = array_search($consumer, $cfgChildren, true);
            if (is_int($producerIndex) && is_int($consumerIndex)) {
                $feedsConsumerArg = $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $producer,
                    $consumer,
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                )
                    || $this->isAdjacentNestedFuncCallProducer($producer, $consumer, $producerIndex, $consumerIndex)
                    || $this->isSiblingMultiArgFuncCallProducer(
                        $producer,
                        $consumer,
                        $producerIndex,
                        $consumerIndex,
                        $cfgChildren
                    );
            }
        }

        // Producer may feed a dead temp via position matching when operand identity
        // does not link result→arg (#11313, #11409); unrelated named locals are skipped above.
        return $feedsConsumerArg;
    }

    /**
     * `return foo()` lowers call opcodes then return; reuse FUNCCALL_EXEC_RETURN slot (#1885).
     */
    private function funcCallExecReturnSlotForReturn(Block $block, Operand $returnExpr): ?int
    {
        $n = $block->nOpCodes;
        if (0 === $n) {
            return null;
        }
        $last = $block->opCodes[$n - 1];
        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN !== $last->type) {
            return null;
        }
        if (!$block->callResultFeedsReturn($returnExpr)) {
            return null;
        }

        return $last->arg1;
    }

    /**
     * php-cfg lowers `return null` to ConstFetch + Temporary; trailing include/call
     * may appear as Terminal_Return with a non-literal operand (#5367, #739).
     */
    private function voidFunctionReturnIsPhpCfgArtifact(Op\Terminal\Return_ $terminal, Block $block): bool
    {
        $expr = $terminal->expr;
        if (null === $expr) {
            return true;
        }
        if (null !== $this->funcCallExecReturnSlotForReturn($block, $expr)) {
            return true;
        }
        if ($expr instanceof Operand\Literal || $expr instanceof Operand\Variable) {
            return false;
        }
        if ($expr instanceof Operand\Temporary) {
            $producer = $this->findCfgProducerForReturnOperand($block->orig, $expr);

            return $producer instanceof Op\Expr\Include_;
        }

        return true;
    }

    private function voidFunctionReturnValueErrorMessage(?Operand $expr, Block $block): string
    {
        $base = 'A void function must not return a value';
        if (null === $expr) {
            return $base;
        }
        if ($expr instanceof Operand\Literal && $this->isNullLiteralOperand($expr)) {
            return $base.' (did you mean "return;" instead of "return null;"?)';
        }
        if (
            ($expr instanceof Operand\Temporary || $expr instanceof Operand\Variable)
            && $this->isNullConstFetchReturnTemporary($block->orig, $expr)
        ) {
            return $base.' (did you mean "return;" instead of "return null;"?)';
        }

        return $base;
    }

    private function isNullLiteralOperand(Operand\Literal $literal): bool
    {
        if (null !== $literal->type && Type::TYPE_NULL === $literal->type->type) {
            return true;
        }

        return 'null' === strtolower((string) ($literal->value ?? ''));
    }

    private function isNullConstFetchReturnTemporary(CfgBlock $cfgBlock, Operand $returnExpr): bool
    {
        $producer = $this->findCfgProducerForReturnOperand($cfgBlock, $returnExpr);
        if (!$producer instanceof Op\Expr\ConstFetch) {
            return false;
        }
        $name = $this->staticNameFromOperand($producer->name);

        return 'null' === strtolower((string) $name);
    }

    private function findCfgProducerForReturnOperand(CfgBlock $cfgBlock, Operand $returnExpr): ?Op
    {
        $returnRoot = Block::cfgVarRoot($returnExpr);
        foreach ($cfgBlock->children as $child) {
            if (!($child instanceof Op\Expr)) {
                continue;
            }
            $result = $child->result;
            if (!$result instanceof Operand) {
                continue;
            }
            if ($result === $returnExpr) {
                return $child;
            }
            if (null !== $returnRoot && Block::cfgVarRoot($result) === $returnRoot) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Result slot from freshly emitted INIT_ARRAY lowering — not a stale operand map (#15848).
     */
    private function slotFromInitArrayLiteralOps(array $arrayOps): ?string
    {
        $slot = null;
        foreach ($arrayOps as $op) {
            if ($op instanceof OpCode && OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $slot = (string) $op->arg1;
            }
        }

        return $slot;
    }

    /** First INIT_ARRAY slot in $block — outer haystack for array_slice (#13684). */
    private function firstInitArraySlotInBlock(Block $block): ?string
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                return (string) $op->arg1;
            }
        }

        return null;
    }
}
