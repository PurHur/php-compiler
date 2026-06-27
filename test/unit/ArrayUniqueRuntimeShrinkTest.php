<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayUniqueJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPUnit\Framework\TestCase;

/** array_unique() JIT routes through ArrayUniqueJitHelper PHP not ArrayBuiltinHelper LLVM (#12341). */
final class ArrayUniqueRuntimeShrinkTest extends TestCase
{
    public function testArrayUniqueRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayUniqueRuntime.php');
        $this->assertStringContainsString('ArrayUniqueJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arrayUnique', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_unique.php');
        $this->assertStringContainsString('ArrayUniqueRuntime::unique', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayUnique', $builtin);
    }

    public function testArrayUniqueJitHelperDedupesSortString(): void
    {
        $ht = self::listTable('a', 'b', 'a');
        $out = ArrayUniqueJitHelper::unique($ht, StdlibConstants::SORT_STRING);
        $this->assertSame(2, self::countElements($out));
    }

    public function testArrayUniqueJitHelperSortRegularScalarEquivalence(): void
    {
        $ht = new HashTable();
        foreach ([1, '1', 1.0] as $i => $raw) {
            $var = new Variable();
            if (\is_int($raw)) {
                $var->int($raw);
            } elseif (\is_float($raw)) {
                $var->float($raw);
            } else {
                $var->string((string) $raw);
            }
            $ht->addIndex($i, $var);
        }
        $out = ArrayUniqueJitHelper::unique($ht, StdlibConstants::SORT_REGULAR);
        $this->assertSame(1, self::countElements($out));
    }

    private static function countElements(HashTable $ht): int
    {
        $n = 0;
        foreach ($ht->iterateKeyed(true) as $_) {
            ++$n;
        }

        return $n;
    }

    /** @param list<string> $values */
    private static function listTable(string ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($value);
            $ht->append($var);
        }

        return $ht;
    }
}
