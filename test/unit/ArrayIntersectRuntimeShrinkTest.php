<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayIntersectJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_intersect() NestedJIT via JitVmHelperLink::ensureCompiled (#23627 / peer #23116).
 */
final class ArrayIntersectRuntimeShrinkTest extends TestCase
{
    public function testArrayIntersectRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayIntersectRuntime.php');
        $this->assertStringContainsString('ArrayIntersectJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayIntersect', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_intersect.php');
        $this->assertStringContainsString('ArrayIntersectRuntime::intersect', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayIntersect', $builtin);
    }

    public function testArrayIntersectJitHelperSingleCopy(): void
    {
        $base = self::listTable('a', 'b', 'c');
        $copy = ArrayIntersectJitHelper::intersectSingleCopy($base);
        $this->assertNotSame($base, $copy);
        $this->assertSame(3, $copy->getNumElements());
    }

    public function testArrayIntersectJitHelperKeepsSharedValues(): void
    {
        $first = self::listTable('a', 'b', 'c');
        $other = self::listTable('b', 'd');
        $result = ArrayIntersectJitHelper::intersectTwo($first, $other);
        $values = [];
        foreach ($result->iterateKeyed(true) as [, $value]) {
            $values[] = $value->resolveIndirect()->toString();
        }
        $this->assertSame(['b'], $values);
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
