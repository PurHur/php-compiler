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
 * a smaller Concern TU (array_splice / mbstring / filter helpers). Embedded-literal
 * resolve body lives in {@see MatchInlineCallArgProducerEmbeddedLiteralsResolve}.
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

}
