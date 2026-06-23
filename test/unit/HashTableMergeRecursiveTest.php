<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_merge_recursive HashTable parity (#10732). */
final class HashTableMergeRecursiveTest extends TestCase
{
    public function testScalarDuplicateKeyPromotesToList(): void
    {
        $left = $this->array(['a' => 1]);
        $right = $this->array(['a' => 2]);
        $merged = $left->mergeRecursiveCopy($right);
        $this->assertSame(
            ['a' => [0 => 1, 1 => 2]],
            $this->export($merged)
        );
    }

    public function testNestedDistinctSubkeysMergeFlat(): void
    {
        $left = $this->array(['a' => ['x' => 1]]);
        $right = $this->array(['a' => ['y' => 2]]);
        $merged = $left->mergeRecursiveCopy($right);
        $this->assertSame(
            ['a' => ['x' => 1, 'y' => 2]],
            $this->export($merged)
        );
    }

    public function testScalarPlusArrayPromotesAndAppends(): void
    {
        $left = $this->array(['a' => 1]);
        $right = $this->array(['a' => [2, 3]]);
        $merged = $left->mergeRecursiveCopy($right);
        $this->assertSame(
            ['a' => [0 => 1, 1 => 2, 2 => 3]],
            $this->export($merged)
        );
    }

    /** @param array<string|int, mixed> $data */
    private function array(array $data): HashTable
    {
        $ht = new HashTable();
        foreach ($data as $key => $value) {
            $var = new Variable();
            if (\is_array($value)) {
                $var->array($this->array($value));
            } elseif (\is_int($value)) {
                $var->int($value);
            } else {
                $var->string((string) $value);
            }
            if (\is_int($key)) {
                $ht->addIndex($key, $var);
            } else {
                $ht->add($key, $var);
            }
        }

        return $ht;
    }

    private function export(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $resolved = $value->resolveIndirect();
            $k = Variable::TYPE_INTEGER === $key->type ? $key->toInt() : $key->toString();
            if (Variable::TYPE_ARRAY === $resolved->type) {
                $out[$k] = $this->export($resolved->toArray());
            } elseif (Variable::TYPE_INTEGER === $resolved->type) {
                $out[$k] = $resolved->toInt();
            } else {
                $out[$k] = $resolved->toString();
            }
        }

        return $out;
    }
}
