<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * array_merge / preg_replace / nested-array / array_combine family and
 * hoisted Array_+Assign dead-temp inline call-arg producer match (#36387 / #36403).
 *
 * Extracted from {@see InlineCallArgProducerMatch} so gen-0 split-TU can hollow a
 * smaller Concern TU. Positive-match only — null means continue later heuristics
 * (named-parameter / embedded-literal terminal exits stay in the hub). Mirrors
 * php-src Zend/zend_compile.c call-arg operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait InlineCallArgMergeFamilyAndHoistedAssignProducers
{
    /**
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     * @return Op\Expr|null matched producer; null to continue later heuristics
     */
    private function tryMatchInlineCallArgMergePregNestedAndCombineProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null,
        ?Block $block = null,
        ?string $calleeName = null
    ): ?Op\Expr {
        $inlineFuncName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        $producerCount = count($producers);
        $argCount = count($callArgs);
        if (
            \in_array($inlineFuncName, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
            && 2 === $argCount
            && $argIndex < $producerCount
        ) {
            $mergeMapped = $this->matchArrayMergeFamilyInlineCallArgProducer($producers, $argIndex);
            if (null !== $mergeMapped) {
                return $mergeMapped;
            }
        }
        if (\in_array($inlineFuncName, ['preg_replace', 'substr_replace'], true)) {
            $pregReplaceMapped = $this->matchInlineArrayProducersToArrayCallArgs($producers, $callArgs, $argIndex);
            if (null !== $pregReplaceMapped) {
                return $pregReplaceMapped;
            }
        }
        $trailingComparator = $this->matchTrailingComparatorInlineCallArgProducer(
            $producers,
            $callArgs,
            $argIndex,
            $inlineFuncName
        );
        if (null !== $trailingComparator) {
            return $trailingComparator;
        }
        $siblingNestedArray = $this->matchSiblingNestedArrayLiteralCallArgProducer(
            $producers,
            $argIndex,
            $argCount
        );
        if (null !== $siblingNestedArray) {
            return $siblingNestedArray;
        }
        $foldedFirstNested = $this->matchFoldedFirstNestedSiblingArrayLiteralCallArgProducer(
            $producers,
            $argIndex,
            $argCount,
            $callArgs
        );
        if (null !== $foldedFirstNested) {
            return $foldedFirstNested;
        }
        $soleNestedHaystack = $this->matchSoleNestedInlineArrayHaystackProducer(
            $producers,
            $callArgs,
            $argIndex
        );
        if (null !== $soleNestedHaystack) {
            return $soleNestedHaystack;
        }
        if ('array_combine' === $inlineFuncName && 2 === $argCount && $producerCount >= 2) {
            $arrayCombinePair = $this->matchArrayCombineInlineProducers($producers, $argIndex);
            if (null !== $arrayCombinePair) {
                return $arrayCombinePair;
            }
        }

        return null;
    }

    /**
     * php-cfg hoists `$a = [...]` as Array_+Assign before `array_key_exists('k', $a)` (#9456).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     * @return Op\Expr|null matched producer; null to continue later heuristics
     */
    private function tryMatchInlineCallArgHoistedAssignDeadTempProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null,
        ?Block $block = null,
        ?string $calleeName = null
    ): ?Op\Expr {
        $inlineFuncName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        $producerCount = count($producers);
        $argCount = count($callArgs);
        // php-cfg hoists `$a = [...]` as Array_+Assign before `array_key_exists('k', $a)` (#9456).
        $callArg = $callArgs[$argIndex] ?? null;
        if (
            $this->callArgIsDeadInlineTemporary($callArg)
            && null !== $cfgCallOp
            && null !== $block
            && null !== $block->orig
            && !(
                $producerCount > $argCount
                && null !== $this->soleNonEmbeddedCallArgIndex($callArgs)
            )
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
                    // Nested inline array consumed multiple Array_ slots — do not wire trailing int arg (#12008, #13697).
                    $outerArray = $this->matchOutermostNestedInlineArrayProducerForArgZero(
                        $producers,
                        $argIndex,
                        $argCount,
                        $producerCount
                    );
                    if (null !== $outerArray) {
                        return $outerArray;
                    }
                    $trailingConst = $this->matchNestedArrayTrailingConstFetchCallArgProducer(
                        $producers,
                        $callArgs,
                        $argIndex
                    );
                    if (null !== $trailingConst) {
                        return $trailingConst;
                    }
                    $byIndex = null;
                }
                if ($byIndex instanceof Op\Expr\Array_ && null !== $callArg) {
                    // array_reverse([...], true) — nested FuncCall feeds the dead temp, not hoisted Array_ (#14042).
                    $directCall = $this->matchDirectResultInlineCallArgProducer($producers, $callArg);
                    if (
                        (
                            $directCall instanceof Op\Expr\FuncCall
                            || $directCall instanceof Op\Expr\NsFuncCall
                            || $directCall instanceof Op\Expr\StaticCall
                            || $directCall instanceof Op\Expr\MethodCall
                        )
                        && !$this->callArgOperandExpectsArrayProducer($callArg)
                    ) {
                        return $directCall;
                    }
                }
                if (
                    null !== $byIndex
                    && !(
                        $byIndex instanceof Op\Expr\Array_
                        && $this->producersIncludeInlineArrayUnionPlus($producers)
                    )
                    && (
                        $byIndex instanceof Op\Expr\FuncCall
                        || $byIndex instanceof Op\Expr\NsFuncCall
                        || $byIndex instanceof Op\Expr\StaticCall
                        || $byIndex instanceof Op\Expr\MethodCall
                        || $byIndex instanceof Op\Expr\BinaryOp\BitwiseOr
                        || $byIndex instanceof Op\Expr\BinaryOp\BitwiseAnd
                        || $byIndex instanceof Op\Expr\BinaryOp\BitwiseXor
                        || $byIndex instanceof Op\Expr\ClassConstFetch
                        || $byIndex instanceof Op\Expr\Cast
                        || $this->inlineCallArgProducerUsesExprResultSlot($byIndex)
                    )
                ) {
                    if ($byIndex instanceof Op\Expr\ClassConstFetch && null !== $callArg) {
                        $enumPropertyProducer = $this->matchDirectResultInlineCallArgProducer($producers, $callArg);
                        if ($enumPropertyProducer instanceof Op\Expr\PropertyFetch
                            || $enumPropertyProducer instanceof Op\Expr\NullsafePropertyFetch
                            || $enumPropertyProducer instanceof Op\Expr\NullsafeMethodCall) {
                            return $enumPropertyProducer;
                        }
                    }
                    if (
                        (
                            $byIndex instanceof Op\Expr\FuncCall
                            || $byIndex instanceof Op\Expr\NsFuncCall
                            || $byIndex instanceof Op\Expr\StaticCall
                            || $byIndex instanceof Op\Expr\MethodCall
                        )
                        && null !== $callArg
                        && $this->callArgOperandExpectsArrayProducer($callArg)
                    ) {
                        // array_slice([..], array_search(...)) — nested int builtin is arg #1 (#13684).
                        $arrayProducers = array_values(array_filter(
                            $producers,
                            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
                        ));
                        if (isset($arrayProducers[$argIndex])) {
                            return $arrayProducers[$argIndex];
                        }
                        if (0 === $argIndex && [] !== $arrayProducers) {
                            return $arrayProducers[0];
                        }
                        // in_array('x', g(), true) — hoisted FuncCall haystack, not Array_ (#16265).
                        if (
                            \in_array($inlineFuncName, ['in_array', 'array_search'], true)
                            && 1 === $argIndex
                        ) {
                            return $byIndex;
                        }
                    } else {
                        if ($byIndex instanceof Op\Expr\Array_) {
                            $outerArray = $this->matchOutermostNestedInlineArrayProducerForArgZero(
                                $producers,
                                $argIndex,
                                $argCount,
                                $producerCount
                            );
                            if (null !== $outerArray) {
                                return $outerArray;
                            }
                        }

                        return $byIndex;
                    }
                }
                if (
                    null !== $byIndex
                    && $byIndex instanceof Op\Expr\ConstFetch
                    && null !== $callArg
                    && !$this->callArgOperandExpectsArrayProducer($callArg)
                ) {
                    foreach ($producers as $candidate) {
                        if (
                            $candidate instanceof Op\Expr\Cast
                            && $this->operandsReferToSameVariable($candidate->expr, $byIndex->result)
                        ) {
                            return $candidate;
                        }
                    }
                    $castProducer = $this->matchDirectResultInlineCallArgProducer($producers, $callArg);
                    if ($castProducer instanceof Op\Expr\Cast) {
                        return $castProducer;
                    }
                    $last = $producers[$producerCount - 1] ?? null;
                    // ini_set('error_reporting', (string)(E_ALL & ~MASK)) — ConstFetch prelude + trailing Cast (#15460).
                    if ($last instanceof Op\Expr\Cast) {
                        return $last;
                    }
                    // error_reporting(E_ALL & ~E_NOTICE) — ConstFetch prelude + trailing BitwiseAnd (#15391).
                    if (1 === $argCount) {
                        if (
                            $last instanceof Op\Expr\BinaryOp\BitwiseOr
                            || $last instanceof Op\Expr\BinaryOp\BitwiseAnd
                            || $last instanceof Op\Expr\BinaryOp\BitwiseXor
                        ) {
                            return $last;
                        }
                    }

                    return $byIndex;
                }
            }
        }

        return null;
    }
}
