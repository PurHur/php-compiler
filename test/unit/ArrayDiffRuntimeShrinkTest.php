<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayDiffJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_diff() NestedJIT via JitVmHelperLink::ensureCompiled (#23116 / peer #22954).
 */
final class ArrayDiffRuntimeShrinkTest extends TestCase
{
    public function testArrayDiffRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayDiffRuntime.php');
        $this->assertStringContainsString('ArrayDiffJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayDiff', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_diff.php');
        $this->assertStringContainsString('ArrayDiffRuntime::diff', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayDiff', $builtin);
    }

    public function testArrayDiffJitHelperSingleCopy(): void
    {
        $base = self::listTable('a', 'b', 'c');
        $copy = ArrayDiffJitHelper::diffSingleCopy($base);
        $this->assertNotSame($base, $copy);
        $this->assertSame(3, $copy->getNumElements());
    }

    public function testArrayDiffJitHelperRemovesValues(): void
    {
        $first = self::listTable('a', 'b', 'c');
        $other = self::listTable('b');
        $result = ArrayDiffJitHelper::diffTwo($first, $other);
        $values = [];
        foreach ($result->iterateKeyed(true) as [, $value]) {
            $values[] = $value->resolveIndirect()->toString();
        }
        sort($values);
        $this->assertSame(['a', 'c'], $values);
    }

    /** @param list<string> $values */
    private static function listTable(string ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable();
            $var->string($value);
            $ht->append($var);
        }

        return $ht;
    }
}
