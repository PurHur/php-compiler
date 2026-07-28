<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\Native;
use PHPLLVM\Builder;

/**
 * Expand call-time `...$spread` packed hashtables into Native fixed-arity args (#24144).
 *
 * {@see JIT::finalizeJitCallArgs()} merges unpack entries into one HT — correct for
 * Internal varargs and pure-variadic user functions (`function f(...$a)`). Fixed-arity
 * Native callees need one LLVM argument per parameter (Zend ZEND_SEND_UNPACK).
 */
final class CallUnpackExpand
{
    private static int $seq = 0;

    /**
     * @return list<Variable>|null null keeps the packed HT (pure variadic at index 0)
     */
    public static function expandPackedForNative(Context $context, Variable $packed, Native $toCall): ?array
    {
        $paramCount = \count($toCall->paramNames);
        if ($paramCount <= 0) {
            $paramCount = \count($toCall->argTypes);
            if (
                $paramCount > 0
                && '__object__*' === $context->getStringFromType($toCall->argTypes[0])
            ) {
                --$paramCount;
            }
        }
        if ($paramCount <= 0) {
            return null;
        }

        $variadicIndex = $toCall->namedArgsVariadicIndex;
        if (0 === $variadicIndex && 1 === $paramCount) {
            // Pure variadic: Native::packVariadicCallArgs already accepts one HT.
            return null;
        }

        $ht = $context->helper->loadValue($packed);
        $sizeT = $context->getTypeFromString('size_t');
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $numSize = $context->builder->truncOrBitCast($num, $sizeT);

        $fixedCount = null === $variadicIndex ? $paramCount : $variadicIndex;
        $out = [];
        for ($i = 0; $i < $fixedCount; ++$i) {
            $out[] = self::readIndexedOrMissing($context, $ht, $numSize, $i);
        }

        if (null !== $variadicIndex) {
            $out[$variadicIndex] = self::sliceFrom($context, $ht, $numSize, $fixedCount);
        }

        return $out;
    }

    private static function readIndexedOrMissing(
        Context $context,
        \PHPLLVM\Value $ht,
        \PHPLLVM\Value $numSize,
        int $index
    ): Variable {
        $tag = (string) (++self::$seq);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $sizeT->constInt($index, false);
        $inBounds = $context->builder->icmp(Builder::INT_ULT, $idx, $numSize);

        $slot = JitValueBox::alloc($context);
        $dest = JitValueBox::pointer($context, $slot);
        $has = BasicBlockHelper::append($context, 'call_unpack_has_'.$tag);
        $miss = BasicBlockHelper::append($context, 'call_unpack_miss_'.$tag);
        $done = BasicBlockHelper::append($context, 'call_unpack_done_'.$tag);
        $context->builder->branchIf($inBounds, $has, $miss);

        $context->builder->positionAtEnd($has);
        $elem = HashTableHelper::readIndexedToValueBox($context, $ht, $idx);
        JitValueBox::copyFromPointer(
            $context,
            $dest,
            JitValueBox::pointer($context, $elem->value)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($miss);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $dest
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    private static function sliceFrom(
        Context $context,
        \PHPLLVM\Value $ht,
        \PHPLLVM\Value $numSize,
        int $offset
    ): Variable {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $off = $i64->constInt($offset, false);
        $hasLength = $i1->constInt(1, false);
        $numI64 = $context->builder->zExtOrBitCast($numSize, $i64);
        $remain = $context->builder->sub($numI64, $off);
        $neg = $context->builder->icmp(Builder::INT_SLT, $remain, $i64->constInt(0, false));
        $length = $context->builder->select($neg, $i64->constInt(0, false), $remain);
        $sliced = HashTableSliceLlvm::slice($context, $ht, $off, $hasLength, $length, null);

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $sliced
        );
    }
}
