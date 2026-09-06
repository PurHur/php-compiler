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
 * Specialized inline call-arg producer matchers (#36387 / #36403).
 *
 * Extracted from {@see InlineCallArgProducerMatch} so gen-0 split-TU can hollow
 * a smaller Concern TU (array_splice / mbstring / filter / embedded-literal paths).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait MatchInlineCallArgProducerWithEmbeddedLiterals
{
    /**
     * Map hoisted inline producers when php-cfg embeds literal call args (#8561, #8796).
     *
     * e.g. in_array(1, [1, 2, 3], true) — producers [Array_, ConstFetch] align to args 1 and 2, not 0.
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchArraySpliceUnaryOffsetReplacementProducers(
        array $producers,
        int $argIndex,
        int $argCount,
        ?string $inlineFuncName
    ): ?Op\Expr {
        if ('array_splice' !== $inlineFuncName || $argCount < 4 || 2 !== \count($producers)) {
            return null;
        }
        $unaryProducer = null;
        $replacementProducer = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                $unaryProducer = $producer;
            } elseif ($producer instanceof Op\Expr\Array_) {
                $replacementProducer = $producer;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($producer->name);
                if (null !== $name && 'null' === strtolower($name)) {
                    $replacementProducer = $producer;
                }
            }
        }
        if (null === $unaryProducer || null === $replacementProducer) {
            return null;
        }
        if (1 === $argIndex) {
            return $unaryProducer;
        }
        if ($argIndex === $argCount - 1) {
            return $replacementProducer;
        }

        return null;
    }

    /**
     * mb_substr($s, -N, null[, $enc]) / mb_strcut — hoisted UnaryMinus offset + null length (#16481).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchMbstringUnaryOffsetNullLengthProducers(
        array $producers,
        int $argIndex,
        int $argCount,
        ?string $inlineFuncName
    ): ?Op\Expr {
        $func = strtolower((string) $inlineFuncName);
        if (!\in_array($func, ['mb_substr', 'mb_strcut'], true) || $argCount < 3 || 2 !== \count($producers)) {
            return null;
        }
        $unaryProducer = null;
        $nullProducer = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                $unaryProducer = $producer;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($producer->name);
                if (null !== $name && 'null' === strtolower($name)) {
                    $nullProducer = $producer;
                }
            }
        }
        if (null === $unaryProducer || null === $nullProducer) {
            return null;
        }
        if (1 === $argIndex) {
            return $unaryProducer;
        }
        if (2 === $argIndex) {
            return $nullProducer;
        }

        return null;
    }

    private function matchFilterExtensionInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?string $inlineFuncName
    ): ?Op\Expr {
        if ('filter_var' === $inlineFuncName && 3 === \count($callArgs)) {
            $leadingConstNested = $this->splitLeadingConstFetchWithNestedArrayLiteralChain($producers);
            if (null !== $leadingConstNested) {
                [$constFetch, $arrayChain] = $leadingConstNested;

                return match ($argIndex) {
                    1 => $constFetch,
                    2 => $arrayChain[\count($arrayChain) - 1],
                    default => null,
                };
            }
            $leadingConstArray = $this->splitLeadingConstFetchWithArrayLiteralCallArg($producers);
            if (null !== $leadingConstArray) {
                [$constFetch, $array] = $leadingConstArray;

                return match ($argIndex) {
                    1 => $constFetch,
                    2 => $array,
                    default => null,
                };
            }
        }
        if ('filter_input' === $inlineFuncName && 4 === \count($callArgs)) {
            $constFetches = array_values(array_filter(
                $producers,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ConstFetch
            ));
            $arrayProducers = array_values(array_filter(
                $producers,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
            ));
            if (1 === \count($constFetches) && \count($arrayProducers) >= 1) {
                return match ($argIndex) {
                    2 => $constFetches[0],
                    3 => $arrayProducers[\count($arrayProducers) - 1],
                    default => null,
                };
            }
            if (\count($constFetches) >= 2 && [] !== $arrayProducers) {
                return match ($argIndex) {
                    0 => $constFetches[0],
                    2 => $constFetches[1],
                    3 => $arrayProducers[\count($arrayProducers) - 1],
                    default => null,
                };
            }
        }

        return null;
    }

    private function matchInlineCallArgProducerWithEmbeddedLiterals(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null,
        ?Block $block = null,
        ?string $calleeName = null
    ): ?Op\Expr {
        $inlineFuncName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        $filterInline = $this->matchFilterExtensionInlineCallArgProducer(
            $producers,
            $callArgs,
            $argIndex,
            $inlineFuncName
        );
        if (null !== $filterInline) {
            return $filterInline;
        }
        $mappedArraySplice = $this->matchArraySpliceUnaryOffsetReplacementProducers(
            $producers,
            $argIndex,
            \count($callArgs),
            $inlineFuncName
        );
        if (null !== $mappedArraySplice) {
            return $mappedArraySplice;
        }
        $mappedMbstring = $this->matchMbstringUnaryOffsetNullLengthProducers(
            $producers,
            $argIndex,
            \count($callArgs),
            $inlineFuncName
        );
        if (null !== $mappedMbstring) {
            return $mappedMbstring;
        }
        // array_combine([...], [...]) — sibling Array_ producers map to keys/values by order (#10214).
        if (
            'array_combine' === $inlineFuncName
            && 2 === \count($callArgs)
            && \count($producers) >= 2
        ) {
            $arrayCombinePair = $this->matchArrayCombineInlineProducers($producers, $argIndex);
            if (null !== $arrayCombinePair) {
                return $arrayCombinePair;
            }
        }
        // array_merge(array_keys($src), ['b']) / array_merge(['a'=>1], array_keys(...)) (#12450, #13704, #13760).
        if (
            \in_array($inlineFuncName, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
            && 2 === \count($callArgs)
            && \count($producers) >= 2
        ) {
            $arrayMergePair = $this->matchArrayMergeFamilyInlineCallArgProducer($producers, $argIndex);
            if (null !== $arrayMergePair) {
                return $arrayMergePair;
            }
        }
        // explode(PATH_SEPARATOR, get_include_path()) — ConstFetch prelude + sibling FuncCall (#15833).
        if ('explode' === $inlineFuncName && 2 === \count($callArgs) && \count($producers) >= 2) {
            $constProducer = null;
            $funcProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\ConstFetch) {
                    $constProducer = $producer;
                } elseif ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    $funcProducer = $producer;
                }
            }
            if (null !== $constProducer && null !== $funcProducer) {
                return (0 === $argIndex) ? $constProducer : $funcProducer;
            }
        }
        // fseek($stream, -1, SEEK_END) — hoisted UnaryMinus offset + ConstFetch whence (#16523, #13451).
        if ('fseek' === $inlineFuncName && \count($callArgs) >= 3) {
            $unaryProducer = null;
            $constProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                    $unaryProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $constProducer = $producer;
                }
            }
            if (null !== $unaryProducer && null !== $constProducer) {
                return match ($argIndex) {
                    1 => $unaryProducer,
                    2 => $constProducer,
                    default => null,
                };
            }
        }
        // preg_split(..., -1, PREG_SPLIT_*) / explode(..., -1) — limit/flags from UnaryMinus/ConstFetch, not prior sibling FuncCall (#13423, #13424).
        if (
            ('preg_split' === $inlineFuncName && ($argIndex === 2 || $argIndex === 3))
            || ('explode' === $inlineFuncName && 2 === $argIndex)
        ) {
            $unaryProducer = null;
            $constProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                    $unaryProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $constProducer = $producer;
                }
            }
            if (2 === $argIndex) {
                return $unaryProducer;
            }
            if (3 === $argIndex) {
                return $constProducer;
            }
        }
        // json_decode(g(), true) / json_decode(json_encode($d), true) — FuncCall + bool ConstFetch (#24137).
        if ('json_decode' === $inlineFuncName && \count($callArgs) >= 2 && \count($callArgs) < 4) {
            $funcProducer = null;
            $boolConstProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    $funcProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch || $producer instanceof Op\Expr\ClassConstFetch) {
                    $name = $this->staticNameFromOperand($producer->name);
                    if (null !== $name && \in_array(strtolower($name), ['true', 'false'], true)) {
                        $boolConstProducer = $producer;
                    }
                }
            }
            if (null !== $funcProducer && null !== $boolConstProducer) {
                return match ($argIndex) {
                    0 => $funcProducer,
                    1 => $boolConstProducer,
                    default => null,
                };
            }
        }
        // json_decode(g(), true, 512, JSON_THROW_ON_ERROR) — FuncCall + ConstFetch preludes (#12009, #15441).
        if ('json_decode' === $inlineFuncName && \count($callArgs) >= 4) {
            $funcProducer = null;
            $constProducers = [];
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    $funcProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch || $producer instanceof Op\Expr\ClassConstFetch) {
                    $constProducers[] = $producer;
                }
            }
            if (null !== $funcProducer && \count($constProducers) >= 2) {
                return match ($argIndex) {
                    0 => $funcProducer,
                    1 => $constProducers[0],
                    3 => $constProducers[\count($constProducers) - 1],
                    default => null,
                };
            }
        }
        // array_chunk(range(1,5), 2, true) — FuncCall haystack + trailing preserve_keys ConstFetch (#11767).
        if ('array_chunk' === $inlineFuncName && \count($callArgs) >= 3) {
            $funcProducer = null;
            $boolConstProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    $funcProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch || $producer instanceof Op\Expr\ClassConstFetch) {
                    $name = $this->staticNameFromOperand($producer->name);
                    if (null !== $name && \in_array(strtolower($name), ['true', 'false'], true)) {
                        $boolConstProducer = $producer;
                    }
                }
            }
            if (null !== $funcProducer && null !== $boolConstProducer) {
                return match ($argIndex) {
                    0 => $funcProducer,
                    2 => $boolConstProducer,
                    default => null,
                };
            }
        }
        if (null !== $cfgCallOp && null !== $block && null !== $block->orig) {
            $leadingProducer = $producers[0] ?? null;
            if ($leadingProducer instanceof Op\Expr\FuncCall || $leadingProducer instanceof Op\Expr\NsFuncCall) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (null !== $callArg && $this->callArgIsDeadInlineTemporary($callArg)) {
                    $byIndex = $this->inlineHoistedProducerForCallArgIndex(
                        $cfgCallOp,
                        $argIndex,
                        $producers,
                        $block->orig->children,
                        $block
                    );
                    if ($byIndex instanceof Op\Expr) {
                        return $byIndex;
                    }
                }
            }
        }
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null !== $nestedTrailing) {
            [$arrayChain, $trailing] = $nestedTrailing;
            if (0 === $argIndex) {
                return $arrayChain[\count($arrayChain) - 1];
            }
            if ($argIndex === \count($callArgs) - 1 && [] !== $trailing) {
                return $trailing[\count($trailing) - 1];
            }
        }
        if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null === $callArg) {
            return null;
        }
        foreach ($producers as $producer) {
            if ($this->operandsReferToSameVariable($producer->result, $callArg)) {
                if (
                    \in_array($inlineFuncName, ['array_merge', 'array_merge_recursive'], true)
                    && ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                    && $argIndex > 0
                ) {
                    continue;
                }
                return $producer;
            }
        }

        // php-cfg dead call-arg temps: hoisted producers align to non-embedded arg slots (#9324).
        $nonEmbeddedArgIndices = [];
        foreach ($callArgs as $i => $arg) {
            if (null === $arg || $this->isEmbeddedCallLiteralArg($arg)) {
                continue;
            }
            if (
                null !== $cfgCallOp
                && null !== $block
                && $this->callArgUsesInlineArrayNotInHoistedProducers($arg, $block, $cfgCallOp, $i, $producers)
            ) {
                continue;
            }
            $nonEmbeddedArgIndices[] = $i;
        }
        // f(CONST, null|false|true, [...]) — multiple leading ConstFetches + trailing Array_
        // must map 1:1 to non-array dead-temp args (#22368; openssl_cms_verify null $certificates).
        // filter_var FILTER_* + ['flags'=>FILTER_*] keeps element ConstFetches in producers but only
        // one const call-arg — count mismatch skips this path.
        $leadingConstArrayMulti = $this->splitLeadingConstFetchWithArrayLiteralCallArg($producers);
        if (null !== $leadingConstArrayMulti) {
            /** @var list<Op\Expr\ConstFetch> $leadingConsts */
            $leadingConsts = [];
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\ConstFetch) {
                    $leadingConsts[] = $producer;
                    continue;
                }
                if ($producer instanceof Op\Expr\Array_) {
                    break;
                }
            }
            if (\count($leadingConsts) >= 2) {
                [, $arrayProducer] = $leadingConstArrayMulti;
                $arrayArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                $constArgIndices = [];
                foreach ($nonEmbeddedArgIndices as $idx) {
                    if ($idx !== $arrayArgIndex) {
                        $constArgIndices[] = $idx;
                    }
                }
                if (\count($leadingConsts) === \count($constArgIndices)) {
                    if ($argIndex === $arrayArgIndex) {
                        return $arrayProducer;
                    }
                    foreach ($constArgIndices as $ordinal => $idx) {
                        if ($argIndex === $idx) {
                            return $leadingConsts[$ordinal];
                        }
                    }

                    return null;
                }
            }
        }
        // substr(..., -N) / mb_substr(..., -N) — UnaryMinus maps to sole non-embedded offset/length (#13422, #13424).
        if (
            \in_array($inlineFuncName, ['substr', 'mb_substr', 'mb_strcut'], true)
            && 1 === \count($producers)
            && ($producers[0] instanceof Op\Expr\UnaryMinus || $producers[0] instanceof Op\Expr\UnaryPlus)
            && \in_array($argIndex, $nonEmbeddedArgIndices, true)
        ) {
            return $producers[0];
        }
        // preg_match(..., $matches, PREG_OFFSET_CAPTURE) — ConstFetch/BitwiseOr only for flags/offset, not &$matches (#13714).
        if (\in_array($inlineFuncName, ['preg_match', 'preg_match_all'], true)) {
            if (2 === $argIndex) {
                return null;
            }
            if ($argIndex >= 3) {
                $lastProducer = $producers[\count($producers) - 1] ?? null;
                if (
                    $lastProducer instanceof Op\Expr\ConstFetch
                    || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseOr
                    || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseAnd
                    || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseXor
                ) {
                    $trailingNonEmbedded = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                    if ($argIndex === $trailingNonEmbedded) {
                        return $lastProducer;
                    }
                }
                foreach ($producers as $producer) {
                    if (
                        ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus)
                        && $argIndex === (\count($callArgs) - 1)
                    ) {
                        return $producer;
                    }
                }
            }

            return null;
        }
        // array_map(callback, [...], [...]) — map hoisted Array_ producers to array args by order (#10094, #13812).
        if ('array_map' === $inlineFuncName && $argIndex >= 1) {
            $mapped = $this->matchInlineArrayProducersToArrayCallArgs($producers, $callArgs, $argIndex);
            if (null !== $mapped) {
                return $mapped;
            }
        }
        // preg_replace(['/a/'], ['A'], 'subj') — sibling Array_ pattern/replacement + embedded subject (#10808).
        // substr_replace(['a','b'], '.', [1,2], [1,1]) — sibling Array_ string/offset/length (#9124).
        if (\in_array($inlineFuncName, ['preg_replace', 'substr_replace'], true)) {
            $mapped = $this->matchInlineArrayProducersToArrayCallArgs($producers, $callArgs, $argIndex);
            if (null !== $mapped) {
                return $mapped;
            }
        }
        // strtotime('next Monday', strtotime('2024-06-03')) — lone hoisted FuncCall → sole non-embedded arg (#10838).
        if (
            1 === \count($producers)
            && 1 === \count($nonEmbeddedArgIndices)
            && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
            && $argIndex === $nonEmbeddedArgIndices[0]
        ) {
            return $producers[0];
        }
        // in_array(null, [null], true) — Array_ haystack must not lose to hoisted null ConstFetch (#16096, re-#10909).
        if (
            \in_array($inlineFuncName, ['in_array', 'array_search'], true)
            && \count($callArgs) >= 3
        ) {
            $arrayProducerIndex = null;
            $strictBoolProducerIndex = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    $arrayProducerIndex = $pi;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $name = $this->staticNameFromOperand($producer->name);
                    if (null !== $name && \in_array(strtolower($name), ['true', 'false'], true)) {
                        $strictBoolProducerIndex = $pi;
                    }
                }
            }
            if (null !== $arrayProducerIndex) {
                if (1 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if (2 === $argIndex && null !== $strictBoolProducerIndex) {
                    return $producers[$strictBoolProducerIndex];
                }
            }
            $constFuncSplit = $this->splitLeadingConstFetchWithFuncCallCallArg($producers);
            if (null !== $constFuncSplit) {
                [$constFetch, $funcProducer] = $constFuncSplit;
                if (1 === $argIndex) {
                    return $funcProducer;
                }
                if (2 === $argIndex) {
                    return $constFetch;
                }
            }
            if (
                2 === \count($producers)
                && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
                && $producers[1] instanceof Op\Expr\ConstFetch
            ) {
                // in_array('x', g(), true) — cfg unshift order [FuncCall, ConstFetch] (#16265).
                if (1 === $argIndex) {
                    return $producers[0];
                }
                if (2 === $argIndex) {
                    return $producers[1];
                }
            }
        }
        // in_array(E::A, [E::A, E::B], true) — Array_ + trailing ConstFetch map to haystack/strict slots (#8796, #9888).
        if (\count($producers) >= 2) {
            $arrayProducerIndex = null;
            $constFetchIndices = [];
            $unaryProducerIndex = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    $arrayProducerIndex = $pi;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $constFetchIndices[] = $pi;
                } elseif ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                    $unaryProducerIndex = $pi;
                }
            }
            // array_pad([E::A], N, E::B) — inline enum haystack Array_ + trailing pad-value ConstFetch (#8883).
            if (
                'array_pad' === $inlineFuncName
                && \count($callArgs) >= 3
                && null !== $arrayProducerIndex
                && [] !== $constFetchIndices
            ) {
                $padValueArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? 2;
                if (0 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if ($argIndex === $padValueArgIndex) {
                    return $producers[$constFetchIndices[\count($constFetchIndices) - 1]];
                }

                return null;
            }
            // extract([...], EXTR_PREFIX_ALL, Prefix::A) — Array_ + ConstFetch + ClassConstFetch (#16041).
            if ('extract' === $inlineFuncName && 3 === \count($callArgs) && null !== $arrayProducerIndex) {
                $classConstIndex = null;
                foreach ($producers as $pi => $producer) {
                    if ($producer instanceof Op\Expr\ClassConstFetch) {
                        $classConstIndex = $pi;
                        break;
                    }
                }
                if (null !== $classConstIndex && [] !== $constFetchIndices) {
                    if (0 === $argIndex) {
                        return $producers[$arrayProducerIndex];
                    }
                    if (1 === $argIndex) {
                        return $producers[$constFetchIndices[0]];
                    }
                    if (2 === $argIndex) {
                        return $producers[$classConstIndex];
                    }

                    return null;
                }
            }
            // array_slice($a, -2, 2, true) — Array_ + UnaryMinus + trailing ConstFetch (#10579, #10809).
            if (
                null !== $arrayProducerIndex
                && null !== $unaryProducerIndex
                && 1 === \count($constFetchIndices)
                && \count($nonEmbeddedArgIndices) >= 3
            ) {
                $arrayArgIndex = $nonEmbeddedArgIndices[0] ?? null;
                $offsetArgIndex = $nonEmbeddedArgIndices[1] ?? null;
                $trailingArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                if ($argIndex === $arrayArgIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if ($argIndex === $offsetArgIndex) {
                    return $producers[$unaryProducerIndex];
                }
                if ($argIndex === $trailingArgIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            $closureProducerIndex = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\ArrowFunction
                    || $producer instanceof Op\Expr\Closure
                    || $producer instanceof Op\Expr\FirstClassCallable) {
                    $closureProducerIndex = $pi;
                    break;
                }
            }
            // array_reduce([...], fn(...), null|false|…) — Array_ + Closure + trailing ConstFetch (#23571).
            // Must run before the generic Array_+ConstFetch mapper below, which would bind the
            // Array_ onto the callback slot (nonEmbeddedArgIndices[1]) and orphan the Closure.
            if (
                'array_reduce' === $inlineFuncName
                && null !== $arrayProducerIndex
                && null !== $closureProducerIndex
                && 1 === \count($constFetchIndices)
                && \count($callArgs) >= 3
            ) {
                if (0 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if (1 === $argIndex) {
                    return $producers[$closureProducerIndex];
                }
                if (2 === $argIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            // array_reduce([...], [$this,'m'], 0) — input Array_ + object-array callable Array_;
            // initial is often an embedded Literal (no ConstFetch producer). Without this, both
            // dead temps bind the stmt-before Array_ and ARG_SEND duplicates the callback (#25766).
            if (
                'array_reduce' === $inlineFuncName
                && null === $closureProducerIndex
                && \count($callArgs) >= 2
            ) {
                $arrayProducerIndices = [];
                foreach ($producers as $pi => $producer) {
                    if ($producer instanceof Op\Expr\Array_) {
                        $arrayProducerIndices[] = $pi;
                    }
                }
                if (2 === \count($arrayProducerIndices)) {
                    if (0 === $argIndex) {
                        return $producers[$arrayProducerIndices[0]];
                    }
                    if (1 === $argIndex) {
                        return $producers[$arrayProducerIndices[1]];
                    }
                    if (2 === $argIndex && 1 === \count($constFetchIndices)) {
                        return $producers[$constFetchIndices[0]];
                    }

                    return null;
                }
            }
            if (null !== $arrayProducerIndex && 1 === \count($constFetchIndices) && \count($nonEmbeddedArgIndices) >= 3) {
                $arrayArgIndex = $nonEmbeddedArgIndices[1] ?? null;
                $literalArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                if ($argIndex === $arrayArgIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if ($argIndex === $literalArgIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            // array_filter($arr, fn(...) => ..., ARRAY_FILTER_USE_BOTH) — hoisted closure + trailing mode const (#10232, #9154).
            if (null !== $closureProducerIndex && 1 === \count($constFetchIndices) && \count($callArgs) >= 3) {
                $callbackArgIndex = null;
                foreach ($nonEmbeddedArgIndices as $idx) {
                    if ($idx > 0) {
                        $callbackArgIndex = $idx;
                        break;
                    }
                }
                $trailingArgIndex = \count($callArgs) - 1;
                if ($argIndex === $callbackArgIndex) {
                    return $producers[$closureProducerIndex];
                }
                if ($argIndex === $trailingArgIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            // preg_replace_callback($pat, fn(...), $arr) / iterator_apply($it, fn(...), [$it]) — closure arg 1, array arg 2 (#10652, #15182).
            if (
                null !== $closureProducerIndex
                && null !== $arrayProducerIndex
                && \in_array($inlineFuncName, ['preg_replace_callback', 'iterator_apply'], true)
            ) {
                if (1 === $argIndex) {
                    return $producers[$closureProducerIndex];
                }
                if (2 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }

                return null;
            }
            // preg_replace_callback_array(['/a/' => fn(...)], $subj, -1[, &$count]) —
            // pattern-map Array_ is arg #0; UnaryMinus limit is arg #2 (closure is map value, #19697).
            if (
                'preg_replace_callback_array' === $inlineFuncName
                && null !== $arrayProducerIndex
            ) {
                if (0 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if (2 === $argIndex && null !== $unaryProducerIndex) {
                    return $producers[$unaryProducerIndex];
                }

                return null;
            }
            // array_reduce([...], fn(...), [...]) — input Array_(s) + closure + initial Array_
            // (#5626). Generic pair logic below takes the *last* Array_ as the sole haystack and
            // returns null for arg #2, scrambling input/callback/initial when both are inline.
            if (
                'array_reduce' === $inlineFuncName
                && null !== $closureProducerIndex
                && \count($callArgs) >= 3
            ) {
                $arrayReduceWired = $this->matchArrayReduceInlineArrayClosureInitialProducer(
                    $producers,
                    $argIndex,
                    $closureProducerIndex
                );
                if (null !== $arrayReduceWired) {
                    return $arrayReduceWired;
                }
            }
            // array_map(fn(...), [...]) / array_reduce([...], fn(...)) — closure + inline Array_ (#10651, #10775).
            // Guard callbackArgIndex >= 0: otherwise 1-(-1)=2 binds limit/flags to the Array_ (#19697).
            if (null !== $closureProducerIndex && null !== $arrayProducerIndex) {
                $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex(
                    $inlineFuncName
                );
                if ($callbackArgIndex >= 0) {
                    $arrayArgIndex = 1 - $callbackArgIndex;
                    if ($argIndex === $callbackArgIndex) {
                        return $producers[$closureProducerIndex];
                    }
                    if ($argIndex === $arrayArgIndex) {
                        return $producers[$arrayProducerIndex];
                    }

                    return null;
                }
            }
            // array_map(intval(...), str_split(...)) / array_filter(str_split(...), is_numeric(...)) — FCC + inline FuncCall haystack (#15487, #15490, #15961).
            if (null !== $closureProducerIndex && null === $arrayProducerIndex) {
                $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($inlineFuncName);
                $haystackProducer = null;
                if (null !== $cfgCallOp && null !== $block) {
                    $haystackProducer = 1 === $callbackArgIndex
                        ? $this->trailingInlineFuncCallHaystackBeforeCfgCall($cfgCallOp, $block)
                        : $this->leadingCallbackFirstHaystackFuncCallBeforeCfgCall($cfgCallOp, $block);
                }
                if (null === $haystackProducer) {
                    $funcCallHaystackIndex = null;
                    foreach ($producers as $pi => $producer) {
                        if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                            $funcCallHaystackIndex = $pi;
                        }
                    }
                    if (null !== $funcCallHaystackIndex) {
                        $haystackProducer = $producers[$funcCallHaystackIndex];
                    }
                }
                if (
                    null !== $haystackProducer
                    && $callbackArgIndex >= 0
                    && 2 === \count($callArgs)
                ) {
                    $arrayArgIndex = 1 - $callbackArgIndex;
                    if ($argIndex === $callbackArgIndex) {
                        return $producers[$closureProducerIndex];
                    }
                    if ($argIndex === $arrayArgIndex) {
                        return $haystackProducer;
                    }

                    return null;
                }
            }
        }
        // array_column([[..],[..]], 'name', null) — outer Array_ + trailing null ConstFetch (#9305).
        if (
            2 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && $producers[1] instanceof Op\Expr\ConstFetch
            && \count($nonEmbeddedArgIndices) >= 2
        ) {
            if ($argIndex === $nonEmbeddedArgIndices[0]) {
                return $producers[0];
            }
            if ($argIndex === $nonEmbeddedArgIndices[1]) {
                return $producers[1];
            }

            return null;
        }
        // array_column([[..],[..]], 'name', null) — legacy arg0-only guard when only one non-embedded slot (#9305).
        if (
            2 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && $producers[1] instanceof Op\Expr\ConstFetch
            && 0 === ($nonEmbeddedArgIndices[0] ?? -1)
            && 0 === $argIndex
            && 1 === \count($nonEmbeddedArgIndices)
        ) {
            return $producers[0];
        }
        // in_array(E::A, [E::A, E::B]) — lone Array_ maps to haystack slot, not enum needle (#8796, #9888).
        if (
            1 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && \count($nonEmbeddedArgIndices) >= 2
            && \count($producers) < \count($nonEmbeddedArgIndices)
        ) {
            $arrayArgIndex = $nonEmbeddedArgIndices[\count($producers)];

            return $argIndex === $arrayArgIndex ? $producers[0] : null;
        }
        // array_column([['x'=>1]], 'x') — lone outer Array_ maps to first non-embedded arg (#9305, #10042).
        if (
            1 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && 1 === \count($nonEmbeddedArgIndices)
            && $argIndex === $nonEmbeddedArgIndices[0]
        ) {
            return $producers[0];
        }
        // array_slice($b, 1, -2) — lone UnaryMinus maps to trailing non-embedded arg (#10579).
        if (
            1 === \count($producers)
            && ($producers[0] instanceof Op\Expr\UnaryMinus || $producers[0] instanceof Op\Expr\UnaryPlus)
            && \count($nonEmbeddedArgIndices) >= 2
        ) {
            $unaryArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1];

            return $argIndex === $unaryArgIndex ? $producers[0] : null;
        }
        // preg_match*() PREG_* | PREG_* — ConstFetch preludes + dead-temp BitwiseOr (#10517, #3148).
        $lastProducer = $producers[\count($producers) - 1] ?? null;
        if (
            $lastProducer instanceof Op\Expr\BinaryOp\BitwiseOr
            || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseXor
        ) {
            $trailingNonEmbedded = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
            if ($argIndex === $trailingNonEmbedded) {
                return $lastProducer;
            }
        }
        // filter_var('x', FILTER_*, ['flags' => FILTER_*]) — embedded arg 0 + ConstFetch/Array_ (#12326).
        // Single leading ConstFetch call-arg (element ConstFetches may also appear in producers).
        $leadingConstArray = $this->splitLeadingConstFetchWithArrayLiteralCallArg($producers);
        if (null !== $leadingConstArray) {
            [$constFetch, $array] = $leadingConstArray;
            /** @var list<Op\Expr\ConstFetch> $leadingConsts */
            $leadingConsts = [];
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\ConstFetch) {
                    $leadingConsts[] = $producer;
                    continue;
                }
                if ($producer instanceof Op\Expr\Array_) {
                    break;
                }
            }
            $arrayArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
            if ($argIndex === $arrayArgIndex) {
                return $array;
            }
            $constArgIndices = [];
            foreach ($nonEmbeddedArgIndices as $idx) {
                if ($idx !== $arrayArgIndex) {
                    $constArgIndices[] = $idx;
                }
            }
            // Multi ConstFetch call-args already handled above (#22368); keep first-const fallback
            // when producers include array-element ConstFetches (filter_var flags-in-options).
            if (\count($leadingConsts) === \count($constArgIndices)) {
                foreach ($constArgIndices as $ordinal => $idx) {
                    if ($argIndex === $idx) {
                        return $leadingConsts[$ordinal];
                    }
                }

                return null;
            }
            foreach ($constArgIndices as $idx) {
                if ($argIndex === $idx) {
                    return $constFetch;
                }
            }

            return null;
        }
        // check('label', explode(..., -N), ['expect']) — UnaryMinus feeds nested callee; FuncCall + Array_ (#13424, strict_types).
        if (
            3 === \count($producers)
            && ($producers[0] instanceof Op\Expr\UnaryMinus || $producers[0] instanceof Op\Expr\UnaryPlus)
            && ($producers[1] instanceof Op\Expr\FuncCall || $producers[1] instanceof Op\Expr\NsFuncCall)
            && $producers[2] instanceof Op\Expr\Array_
            && \count($nonEmbeddedArgIndices) >= 2
        ) {
            if ($argIndex === $nonEmbeddedArgIndices[0]) {
                return $producers[1];
            }
            if ($argIndex === $nonEmbeddedArgIndices[1]) {
                return $producers[2];
            }

            return null;
        }
        // check('label', builtin(...), ['expect'|'literal']) — FuncCall + trailing Array_/literal prelude (#13424).
        if (
            2 === \count($producers)
            && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
            && \count($nonEmbeddedArgIndices) >= 2
            && (
                $producers[1] instanceof Op\Expr\Array_
                || $producers[1] instanceof Op\Expr\ConstFetch
            )
        ) {
            if ($argIndex === $nonEmbeddedArgIndices[0]) {
                return $producers[0];
            }
            if ($argIndex === $nonEmbeddedArgIndices[1]) {
                return $producers[1];
            }

            return null;
        }
        // check('label', explode(..., -1), ['expect']) — lone hoisted FuncCall + trailing Array_ prelude (#13423, #13424).
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
        if (
            null !== $soleHoisted
            && $argIndex === $soleHoisted
            && !(
                \in_array($inlineFuncName, ['array_merge', 'array_merge_recursive'], true)
                && \count($callArgs) >= 2
            )
        ) {
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    return $producer;
                }
            }
        }
        if (\count($producers) !== \count($nonEmbeddedArgIndices)) {
            return null;
        }
        if (null !== $block && null !== $callArg && null !== $this->slotForNamedLocalFromAssignVarOperand($callArg, $block)) {
            return null;
        }
        if ($this->isNamedVariableOperand($callArg)) {
            return null;
        }
        $producerOrdinal = array_search($argIndex, $nonEmbeddedArgIndices, true);
        if (false === $producerOrdinal) {
            return null;
        }
        if ('filter_input' === $inlineFuncName && 4 === \count($callArgs) && 0 === $argIndex) {
            return null;
        }

        return $producers[$producerOrdinal] ?? null;
    }
}
