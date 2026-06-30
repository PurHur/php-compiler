<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayColumnJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_column() JIT routes compile-time key paths through ArrayColumnJitHelper PHP (#14256). */
final class ArrayColumnRuntimeShrinkTest extends TestCase
{
    public function testArrayColumnRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayColumnRuntime.php');
        $this->assertStringContainsString('ArrayColumnJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildColumnArray', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_column.php');
        $this->assertStringContainsString('ArrayColumnRuntime::column', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildColumnArray(', $builtin);
    }

    public function testArrayColumnJitHelperMatchesInlineHaystackRepro(): void
    {
        $ht = new HashTable();
        foreach ([['n' => 'a'], ['n' => 'b']] as $i => $row) {
            $rowHt = new HashTable();
            $cell = new Variable();
            $cell->string('a' === $row['n'] ? 'a' : 'b');
            $rowHt->update('n', $cell);
            $rowVar = new Variable();
            $rowVar->array($rowHt);
            $ht->addIndex($i, $rowVar);
        }
        $out = ArrayColumnJitHelper::columnWithKey($ht, 'n');
        $this->assertSame('a', $out->findIndex(0)?->resolveIndirect()->toString());
        $this->assertSame('b', $out->findIndex(1)?->resolveIndirect()->toString());
    }
}
