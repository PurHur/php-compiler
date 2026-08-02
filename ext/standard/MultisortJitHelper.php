<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_multisort() coupled packed paths — Zend-hosted helper + unit-test SSOT (#15667).
 *
 * JIT/AOT thin user builds use LLVM `__multisort__packed` instead (#26908 / #24010): NestedJIT
 * MultisortJitHelper method dispatch aborts under standalone AOT.
 *
 * php-src: ext/standard/array.c — php_array_multisort
 *
 * NestedJIT note: prefer exportKeyValuePairs over iterate() (#12908 / #23974).
 */
final class MultisortJitHelper
{
    /**
     * @param HashTable $sources packed list of __hashtable__ operands (primary first)
     */
    public static function multisortPacked(HashTable $sources, int $descending): void
    {
        $desc = 0 !== $descending;
        $hts = self::unpackSources($sources);
        $count = \count($hts);
        if ($count < 2) {
            return;
        }

        $length = $hts[0]->getNumElements();
        if ($length < 2) {
            return;
        }

        for ($i = 1; $i < $count; ++$i) {
            if ($hts[$i]->getNumElements() !== $length) {
                throw new \ValueError('Array sizes are inconsistent');
            }
        }

        $primary = $hts[0];
        $first = null;
        foreach ($primary->exportKeyValuePairs(true) as [, $value]) {
            $first = $value;
            break;
        }
        if (null === $first) {
            return;
        }
        $isString = Variable::TYPE_STRING === $first->resolveIndirect()->type;

        for ($outer = 0; $outer < $length - 1; ++$outer) {
            for ($inner = 0; $inner < $length - $outer - 1; ++$inner) {
                $cmp = self::comparePackedAt($primary, $inner, $inner + 1, $isString);
                if ($desc) {
                    $cmp = -$cmp;
                }
                if ($cmp > 0) {
                    foreach ($hts as $ht) {
                        self::swapPackedAt($ht, $inner, $inner + 1);
                    }
                }
            }
        }
    }

    /**
     * @return list<HashTable>
     */
    private static function unpackSources(HashTable $sources): array
    {
        $hts = [];
        foreach ($sources->exportKeyValuePairs(true) as [, $value]) {
            $hts[] = $value->resolveIndirect()->toArray();
        }

        return $hts;
    }

    private static function comparePackedAt(HashTable $ht, int $idxA, int $idxB, bool $isString): int
    {
        $va = $ht->findIndex($idxA);
        $vb = $ht->findIndex($idxB);
        if (null === $va || null === $vb) {
            throw new \LogicException('array_multisort() packed index missing in this compiler build');
        }
        $ra = $va->resolveIndirect();
        $rb = $vb->resolveIndirect();
        if ($isString) {
            return \strcmp($ra->toString(), $rb->toString());
        }
        if (
            EnumCaseSupport::isEnumCaseVariable($ra)
            || EnumCaseSupport::isEnumCaseVariable($rb)
            || Variable::TYPE_OBJECT === $ra->type
            || Variable::TYPE_OBJECT === $rb->type
        ) {
            VmInternalCompare::assertHomogeneousEnumOrObjectValues([$va, $vb], 'array_multisort()');

            return VmInternalCompare::comparePackedValuesForSort($va, $vb);
        }

        return $ra->toInt() <=> $rb->toInt();
    }

    private static function swapPackedAt(HashTable $ht, int $idxA, int $idxB): void
    {
        $va = $ht->findIndex($idxA);
        $vb = $ht->findIndex($idxB);
        if (null === $va || null === $vb) {
            throw new \LogicException('array_multisort() packed index missing in this compiler build');
        }
        $tmp = new Variable();
        $tmp->copyFrom($va);
        $va->copyFrom($vb);
        $vb->copyFrom($tmp);
    }
}
