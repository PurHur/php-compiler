<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Func;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\JIT\OperandName;

use SplObjectStorage;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPTypes\Type;

/**
 * Inline call-arg producer matching (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m). Follows
 * CompileCallArgSends: matchInlineCallArgProducer*. Early dead-temp /
 * sibling-New_ paths live in {@see InlineCallArgDeadTempAndSiblingNewProducers};
 * array_column / mbstring / array_map callback paths live in
 * {@see InlineCallArgArrayColumnMbstringAndCallbackProducers};
 * merge/preg/nested/combine + hoisted-Assign dead-temp paths live in
 * {@see InlineCallArgMergeFamilyAndHoistedAssignProducers};
 * chained-dim / union / nested-New / extra-producer-count paths live in
 * {@see InlineCallArgChainedDimUnionNewAndExtraProducers};
 * equal producer/arg-count paths live in
 * {@see InlineCallArgEqualCountProducers};
 * single-producer / unequal producer·arg-count remainder paths live in
 * {@see InlineCallArgSingleAndUnequalCountProducers};
 * specialized matchers (array_splice / mbstring / filter / embedded literals)
 * live in {@see MatchInlineCallArgProducerWithEmbeddedLiterals}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait InlineCallArgProducerMatch
{
    /**
     * Map a hoisted inline call-arg producer to the callee argument index (#8561, #5799).
     *
     * php-cfg may emit fewer preceding Expr_* producers than call args when literals stay
     * embedded in the FuncCall (e.g. array_fill_keys(array('a'), 'x')).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null,
        ?Block $block = null,
        ?string $calleeName = null
    ): ?Op\Expr
    {
        $callArg = $callArgs[$argIndex] ?? null;
        $inlineFuncName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        $earlyDeadTempOrSiblingNew = $this->tryMatchInlineCallArgDeadTempAndSiblingNewProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (null !== $earlyDeadTempOrSiblingNew) {
            return $earlyDeadTempOrSiblingNew;
        }
        $producerCount = count($producers);
        $argCount = count($callArgs);
        $columnMbstringOrCallback = $this->tryMatchInlineCallArgArrayColumnMbstringAndCallbackProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (null !== $columnMbstringOrCallback) {
            return $columnMbstringOrCallback;
        }
        if (0 === $producerCount) {
            return null;
        }
        $mergePregNestedOrCombine = $this->tryMatchInlineCallArgMergePregNestedAndCombineProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (null !== $mergePregNestedOrCombine) {
            return $mergePregNestedOrCombine;
        }
        if ($this->callIncludesNamedParameter($cfgCallOp)) {
            $callArg = $callArgs[$argIndex] ?? null;
            if (null === $callArg) {
                return null;
            }
            if (
                $this->callArgIsDeadInlineTemporary($callArg)
                && null !== $cfgCallOp
                && null !== $block
                && null !== $block->orig
            ) {
                $byIndex = $this->inlineHoistedProducerForCallArgIndex(
                    $cfgCallOp,
                    $argIndex,
                    $producers,
                    $block->orig->children,
                    $block
                );
                if (null !== $byIndex) {
                    $trailingUnaryProducer = $producers[$producerCount - 1] ?? null;
                    if (
                        $byIndex instanceof Op\Expr\Array_
                        && (
                            $trailingUnaryProducer instanceof Op\Expr\Cast
                            || $trailingUnaryProducer instanceof Op\Expr\Clone_
                            || $trailingUnaryProducer instanceof Op\Expr\New_
                        )
                    ) {
                        return $trailingUnaryProducer;
                    }
                    if (
                        $byIndex instanceof Op\Expr\Array_
                        && null !== $callArg
                        && !$this->callArgOperandExpectsArrayProducer($callArg)
                    ) {
                        $outerArray = $this->matchOutermostNestedInlineArrayProducerForArgZero(
                            $producers,
                            $argIndex,
                            $argCount,
                            $producerCount
                        );
                        if (null !== $outerArray) {
                            return $outerArray;
                        }
                        $byIndex = null;
                    }
                    if (
                        $byIndex instanceof Op\Expr\Array_
                        && $trailingUnaryProducer instanceof Op\Expr\BinaryOp\Plus
                    ) {
                        $byIndex = null;
                    }
                    if (
                        $byIndex instanceof Op\Expr\Array_
                        && $this->producersIncludeInlineArrayUnionPlus($producers)
                    ) {
                        $byIndex = null;
                    }
                    if (null !== $byIndex) {
                        return $byIndex;
                    }
                }
            }
            foreach ($producers as $producer) {
                if (
                    null !== $producer->result
                    && $this->operandsReferToSameVariable($producer->result, $callArg)
                ) {
                    if (
                        $producer instanceof Op\Expr\Array_
                        && !$this->callArgOperandExpectsArrayProducer($callArg)
                    ) {
                        continue;
                    }

                    return $producer;
                }
            }

            return null;
        }
        $hoistedAssignDeadTemp = $this->tryMatchInlineCallArgHoistedAssignDeadTempProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (null !== $hoistedAssignDeadTemp) {
            return $hoistedAssignDeadTemp;
        }
        if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
            $embeddedCallArg = $callArgs[$argIndex] ?? null;
            if (
                $embeddedCallArg instanceof Operand
                && $this->callArgOperandExpectsArrayProducer($embeddedCallArg)
                && $argCount < $producerCount
            ) {
                $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
                if (null !== $nestedTrailing) {
                    [$arrayChain, $trailing] = $nestedTrailing;
                    if (1 + \count($trailing) === $argCount && 0 === $argIndex) {
                        $outer = $arrayChain[\count($arrayChain) - 1] ?? null;
                        if ($outer instanceof Op\Expr\Array_) {
                            return $outer;
                        }
                    }
                }
            }

            return null;
        }
        $chainedDimUnionNewOrExtra = $this->tryMatchInlineCallArgChainedDimUnionNewAndExtraProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (false === $chainedDimUnionNewOrExtra) {
            return null;
        }
        if (null !== $chainedDimUnionNewOrExtra) {
            return $chainedDimUnionNewOrExtra;
        }
        $equalCount = $this->tryMatchInlineCallArgEqualCountProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (false === $equalCount) {
            return null;
        }
        if (null !== $equalCount) {
            return $equalCount;
        }
        $singleOrUnequal = $this->tryMatchInlineCallArgSingleAndUnequalCountProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (null !== $singleOrUnequal) {
            return $singleOrUnequal;
        }

        return null;
    }
}
