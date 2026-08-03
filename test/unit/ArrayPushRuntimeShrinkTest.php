<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayPushJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_push() call-site LLVM append (#27226) — NestedJIT pushFromList avoided.
 */
final class ArrayPushRuntimeShrinkTest extends TestCase
{
    public function testArrayPushRuntimeUsesCallSiteAppendNotNestedIterate(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayPushRuntime.php');
        $this->assertStringContainsString('ArrayBuiltinHelper::appendElement', $runtime);
        $this->assertStringContainsString('storeHashtableInArrayVariable', $runtime);
        $this->assertStringContainsString('#27226', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::push', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function push(', $helper);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_push.php');
        $this->assertStringContainsString('ArrayPushRuntime::push', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::push(', $builtin);

        $jitHelper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayPushJitHelper.php');
        $this->assertStringContainsString('function pushFromList', $jitHelper);
    }

    public function testArrayPushJitHelperAppendsValues(): void
    {
        $ht = self::listTable(1, 2);
        $tail = new Variable();
        $tail->int(3);
        $count = ArrayPushJitHelper::push($ht, $tail);
        $this->assertSame(3, $count);
        $this->assertSame(3, $ht->findIndex(2)?->toInt());
    }

    public function testArrayPushJitHelperZeroValuesReturnsCount(): void
    {
        $ht = self::listTable(4, 5);
        $this->assertSame(2, ArrayPushJitHelper::countElements($ht));
    }

    /** @param list<int|string> $values */
    private static function listTable(int|string ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable();
            if (\is_int($value)) {
                $var->int($value);
            } else {
                $var->string($value);
            }
            $ht->append($var);
        }

        return $ht;
    }
}
