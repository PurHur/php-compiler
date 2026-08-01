<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ArrayMergeRecursiveJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_merge_recursive() NestedJIT via JitVmHelperLink::ensureCompiled (#10183 / #24107 / peer #24077).
 */
final class ArrayMergeRecursiveRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testPhpcArrayMergeRecursiveCRuntimeRemovedFromLinker(): void
    {
        $linker = file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_array_merge_recursive.c', $linker);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_array_merge_recursive.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/phpc_array_merge_recursive.c');
    }

    public function testArrayMergeRecursiveRuntimeUsesJitVmHelperLink(): void
    {
        $runtime = file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ArrayMergeRecursiveRuntime.php');
        $this->assertIsString($runtime);
        $this->assertStringContainsString('ArrayMergeRecursiveJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::mergeRecursive', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::mergeRecursiveOverlay', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);

        $builtin = file_get_contents($this->repoRoot.'/ext/standard/array_merge_recursive.php');
        $this->assertIsString($builtin);
        $this->assertStringContainsString('ArrayMergeRecursiveRuntime::mergeRecursive', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::mergeRecursive', $builtin);
        $this->assertStringContainsString('mergeRecursiveCopy', $builtin);

        $this->assertFileDoesNotExist($this->repoRoot.'/ext/standard/JitArrayMergeRecursive.php');

        $monolith = file_get_contents($this->repoRoot.'/lib/JIT/ArrayBuiltinHelper.php');
        $this->assertIsString($monolith);
        $this->assertStringNotContainsString('function mergeRecursive(', $monolith);
        $this->assertStringNotContainsString('function mergeRecursiveOverlay(', $monolith);
        $this->assertStringNotContainsString('public static function merge(', $monolith);
    }

    public function testArrayMergeRecursiveJitHelperMatchesHashTableSemantics(): void
    {
        $left = self::mapTable(['a' => 1]);
        $right = self::mapTable(['a' => 2, 'b' => 3]);
        $merged = ArrayMergeRecursiveJitHelper::mergeTwo($left, $right);
        $this->assertSame([1, 2], self::readIntList($merged->find('a')?->resolveIndirect()->toArray()));
        $this->assertSame(3, $merged->find('b')?->resolveIndirect()->toInt());

        $single = ArrayMergeRecursiveJitHelper::mergeSingleCopy($left);
        $this->assertSame(1, $single->find('a')?->resolveIndirect()->toInt());

        // Top-level int keys renumber (#26559); nested string-key path keeps dest ints.
        $intLeft = self::indexTable([1 => 'a']);
        $intRight = self::indexTable([1 => 'b']);
        $intMerged = ArrayMergeRecursiveJitHelper::mergeTwo($intLeft, $intRight);
        $this->assertSame(['a', 'b'], self::readStringList($intMerged));

        $nestedLeft = self::mapTableNested(['k' => [1 => 'a']]);
        $nestedRight = self::mapTableNested(['k' => [1 => 'b']]);
        $nested = ArrayMergeRecursiveJitHelper::mergeTwo($nestedLeft, $nestedRight);
        $k = $nested->find('k')?->resolveIndirect()->toArray();
        $this->assertNotNull($k);
        $this->assertSame('a', $k->findIndex(1)?->resolveIndirect()->toString());
        $this->assertSame('b', $k->findIndex(2)?->resolveIndirect()->toString());
    }

    /** @param array<int, string> $pairs */
    private static function indexTable(array $pairs): HashTable
    {
        $ht = new HashTable();
        foreach ($pairs as $key => $value) {
            $v = new Variable();
            $v->string($value);
            $ht->addIndex($key, $v);
        }

        return $ht;
    }

    /** @param array<string, array<int, string>> $pairs */
    private static function mapTableNested(array $pairs): HashTable
    {
        $ht = new HashTable();
        foreach ($pairs as $key => $inner) {
            $innerHt = self::indexTable($inner);
            $v = new Variable();
            $v->array($innerHt);
            $ht->add($key, $v);
        }

        return $ht;
    }

    /** @return list<string> */
    private static function readStringList(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterate(true) as $value) {
            $out[] = $value->resolveIndirect()->toString();
        }

        return $out;
    }

    /** @param array<string, int> $pairs */
    private static function mapTable(array $pairs): HashTable
    {
        $ht = new HashTable();
        foreach ($pairs as $key => $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ht->add($key, $var);
        }

        return $ht;
    }

    private static function readIntList(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $out[$key->resolveIndirect()->toInt()] = $value->resolveIndirect()->toInt();
        }
        ksort($out);

        return array_values($out);
    }
}
