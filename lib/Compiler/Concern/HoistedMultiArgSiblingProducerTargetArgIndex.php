<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;

/**
 * Hoisted multi-arg sibling FuncCall producer → target-arg-index helper (#36387).
 *
 * Extracted from {@see HoistedMultiArgSiblingFuncCallChain} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see siblingMultiArgFuncCallProducerTargetArgIndex}).
 *
 * Call sites and visibility stay identical — move-only. Mirrors php-src
 * Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait HoistedMultiArgSiblingProducerTargetArgIndex
{
    /**
     * @param list<Op> $cfgChildren
     */
    private function siblingMultiArgFuncCallProducerTargetArgIndex(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): ?int {
        $distance = $consumerIndex - $producerIndex;
        if ($distance < 1) {
            return null;
        }
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            1 === $distance
            && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            && property_exists($consumer, 'args')
            && \is_array($consumer->args)
        ) {
            $leadingEmbedded = 0;
            foreach ($consumer->args as $arg) {
                if ($this->isEmbeddedCallLiteralArg($arg)) {
                    ++$leadingEmbedded;
                    continue;
                }
                break;
            }
            // probe('label', g()) / probe('label', in_array(...)) — adjacent hoisted callee (#15846, #16013).
            if ($producerIndex === $consumerIndex - 1) {
                $consumerName = $this->resolveCfgFuncCallName($consumer);
                $firstSiblingAdjacent = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
                $useAdjacentArgZero = true;
                if (null !== $firstSiblingAdjacent) {
                    $adjacentProducer = $cfgChildren[$producerIndex] ?? null;
                    if ($adjacentProducer instanceof Op\Expr) {
                        $outerOrdinalAdjacent = $this->outerSiblingInlineFuncCallProducerOrdinal(
                            $adjacentProducer,
                            $firstSiblingAdjacent,
                            $consumerIndex,
                            $cfgChildren
                        );
                        if (null !== $outerOrdinalAdjacent && $outerOrdinalAdjacent > 0) {
                            // array_intersect(f(g()), f(g())) — trailing outer producer is not arg #0 (#15488).
                            $useAdjacentArgZero = false;
                        }
                    }
                }
                if (
                    $useAdjacentArgZero
                    && (
                        !$this->builtinUsesTrailingComparatorCallback($consumerName)
                        || null === $firstSiblingAdjacent
                        || $producerIndex <= $firstSiblingAdjacent
                    )
                    && (
                        null === $firstSiblingAdjacent
                        || $producerIndex === $firstSiblingAdjacent
                        || $this->countSiblingInlineFuncCallProducers(
                            $firstSiblingAdjacent,
                            $consumerIndex,
                            $cfgChildren
                        ) < 2
                    )
                ) {
                    return $leadingEmbedded;
                }
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null === $firstSibling || $producerIndex === $firstSibling) {
                // probe('label', g()) — sole adjacent hoisted producer feeds first hoisted arg (#15846).
                $consumerName = $this->resolveCfgFuncCallName($consumer);
                if (
                    1 === $distance
                    && \in_array($consumerName, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
                    && 2 === \count($consumer->args)
                ) {
                    for ($i = $producerIndex - 1; $i >= 0; --$i) {
                        $prev = $cfgChildren[$i] ?? null;
                        if ($prev instanceof Op\Expr\Array_) {
                            return 1;
                        }
                        if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                            break;
                        }
                        if (
                            !$this->isUnaryInlineSiblingCallArgExpr($prev)
                            && !($prev instanceof Op\Expr\ConstFetch)
                            && !($prev instanceof Op\Expr\ClassConstFetch)
                        ) {
                            break;
                        }
                    }
                }

                return $leadingEmbedded;
            }
        }
        $mid = $cfgChildren[$producerIndex + 1] ?? null;
        if (2 === $distance && $this->isUnaryInlineSiblingCallArgExpr($mid)) {
            return 0;
        }
        // tempnam(sys_get_temp_dir(), E::A) — FuncCall arg #0, ClassConstFetch prelude (#10303, #9321).
        // in_array('x', g(), true) — embedded needle + FuncCall haystack + ConstFetch strict (#15612, #16013).
        if (
            2 === $distance
            && ($mid instanceof Op\Expr\ClassConstFetch || $mid instanceof Op\Expr\ConstFetch)
        ) {
            $producer = $cfgChildren[$producerIndex] ?? null;
            $priorSibling = $cfgChildren[$producerIndex - 1] ?? null;
            if (
                !($priorSibling instanceof Op\Expr\FuncCall || $priorSibling instanceof Op\Expr\NsFuncCall)
                || !$this->isSiblingInlineCallProducerExpr($priorSibling)
            ) {
                if (
                    ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall
                        || $producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall)
                    && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    $leadingEmbedded = 0;
                    foreach ($consumer->args as $arg) {
                        if ($this->isEmbeddedCallLiteralArg($arg)) {
                            ++$leadingEmbedded;
                            continue;
                        }
                        break;
                    }
                    if ($leadingEmbedded > 0) {
                        return $leadingEmbedded;
                    }
                }

                return 0;
            }
            // in_array(get_class(), get_declared_classes(), true) — use ordinal path, not haystack shortcut (#17882).
        }
        if ($this->firstSiblingInlineFuncCallProducerIndexActive) {
            // Reentrant siblingMultiArg during firstSibling scan — must not recurse into impl (#16012).
            $firstSiblingWhileActive = $this->scanFirstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null !== $firstSiblingWhileActive && $producerIndex >= $firstSiblingWhileActive && $producerIndex < $consumerIndex) {
                $ordinalWhileActive = $this->siblingFuncCallChainHasArrayPrelude(
                    $firstSiblingWhileActive,
                    $consumerIndex,
                    $cfgChildren
                )
                    ? $this->siblingInlineFuncCallProducerOrdinal(
                        $producerIndex,
                        $firstSiblingWhileActive,
                        $cfgChildren
                    )
                    : ($producerIndex - $firstSiblingWhileActive);
                $consumerNameWhileActive = $this->resolveCfgFuncCallName($consumer);
                if ($this->builtinUsesTrailingComparatorCallback($consumerNameWhileActive)) {
                    $callbackArgIndex = \count($consumer->args) - 1;
                    $funcArgIndex = 0;
                    foreach ($consumer->args as $i => $callArg) {
                        if ($i >= $callbackArgIndex) {
                            break;
                        }
                        if (
                            $this->isEmbeddedCallLiteralArg($callArg)
                            || $this->callArgIsDeadInlineTemporary($callArg)
                        ) {
                            if ($funcArgIndex === $ordinalWhileActive) {
                                return $i;
                            }
                            ++$funcArgIndex;
                        }
                    }
                }
                if (
                    ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    $leadingEmbeddedWhileActive = 0;
                    foreach ($consumer->args as $arg) {
                        if ($this->isEmbeddedCallLiteralArg($arg)) {
                            ++$leadingEmbeddedWhileActive;
                            continue;
                        }
                        break;
                    }

                    return $leadingEmbeddedWhileActive + $ordinalWhileActive;
                }
            }

            $arrayLiteralTarget = $this->soleFuncCallBeforeArrayLiteralCallArgTargetIndex(
                $producerIndex,
                $consumerIndex,
                $cfgChildren,
                $consumer
            );
            if (null !== $arrayLiteralTarget) {
                // show(strtoupper(...), ['k'=>false]) — ConstFetch+Array_ must not yield distance-1 (#26367).
                return $arrayLiteralTarget;
            }

            return $distance - 1;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
        if (null === $firstSibling) {
            $arrayLiteralTarget = $this->soleFuncCallBeforeArrayLiteralCallArgTargetIndex(
                $producerIndex,
                $consumerIndex,
                $cfgChildren,
                $consumer
            );
            if (null !== $arrayLiteralTarget) {
                return $arrayLiteralTarget;
            }

            return $distance - 1;
        }
        if ($producerIndex < $firstSibling || $producerIndex >= $consumerIndex) {
            return null;
        }

        $ordinal = $this->siblingInlineFuncCallProducerOrdinal(
            $producerIndex,
            $firstSibling,
            $cfgChildren
        );
        if ($ordinal < 0) {
            return null;
        }
        $producer = $cfgChildren[$producerIndex] ?? null;
        if ($producer instanceof Op\Expr) {
            $outerOrdinal = $this->outerSiblingInlineFuncCallProducerOrdinal(
                $producer,
                $firstSibling,
                $consumerIndex,
                $cfgChildren
            );
            if (null !== $outerOrdinal) {
                $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren);
                $hoistedArgCount = 0;
                if (
                    ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    foreach ($consumer->args as $hoistedArgIndex => $callArg) {
                        if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $hoistedArgIndex, $callArg)) {
                                continue;
                            }
                            ++$hoistedArgCount;
                        }
                    }
                }
                if (\count($outer) === $hoistedArgCount && \count($outer) < $consumerIndex - $firstSibling) {
                    $ordinal = $outerOrdinal;
                }
            }
        }
        if (
            ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            && property_exists($consumer, 'args')
            && is_array($consumer->args)
        ) {
            $consumerName = $this->resolveCfgFuncCallName($consumer);
            if ($this->builtinUsesTrailingComparatorCallback($consumerName)) {
                $callbackArgIndex = \count($consumer->args) - 1;
                $funcArgIndex = 0;
                foreach ($consumer->args as $i => $callArg) {
                    if ($i >= $callbackArgIndex) {
                        break;
                    }
                    if (
                        $this->isEmbeddedCallLiteralArg($callArg)
                        || $this->callArgIsDeadInlineTemporary($callArg)
                    ) {
                        if ($funcArgIndex === $ordinal) {
                            return $i;
                        }
                        ++$funcArgIndex;
                    }
                }
            }
            $leadingEmbedded = 0;
            foreach ($consumer->args as $arg) {
                if ($this->isEmbeddedCallLiteralArg($arg)) {
                    ++$leadingEmbedded;
                    continue;
                }
                break;
            }

            return $leadingEmbedded + $ordinal;
        }

        // MethodCall ChildNode: replaceWith($el, 'txt', $el2) — remap producer ordinal onto
        // non-embedded dead-temp args only when an embedded literal is present (#21901).
        // Scoped to ChildNode mutators so insertBefore($new, $list->item(1)) ordinal legacy is unchanged.
        if (
            (
                $consumer instanceof Op\Expr\MethodCall
                || $consumer instanceof Op\Expr\NullsafeMethodCall
            )
            && property_exists($consumer, 'args')
            && is_array($consumer->args)
        ) {
            $consumerMethod = strtolower((string) ($this->staticNameFromOperand($consumer->name) ?? ''));
            if (
                \in_array($consumerMethod, ['replacewith', 'before', 'after', 'append', 'prepend'], true)
            ) {
                $nonEmbeddedDeadIndices = [];
                $hasEmbeddedLiteral = false;
                foreach ($consumer->args as $i => $callArg) {
                    if ($this->isEmbeddedCallLiteralArg($callArg)) {
                        $hasEmbeddedLiteral = true;
                        continue;
                    }
                    if (
                        $callArg instanceof Operand
                        && $this->callArgIsDeadInlineTemporary($callArg)
                    ) {
                        $nonEmbeddedDeadIndices[] = (int) $i;
                    }
                }
                if ($hasEmbeddedLiteral && isset($nonEmbeddedDeadIndices[$ordinal])) {
                    return $nonEmbeddedDeadIndices[$ordinal];
                }
            }
        }

        return $ordinal;
    }
}
