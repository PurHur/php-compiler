<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayColumnJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_column() JIT routes key paths through ArrayColumnJitHelper PHP (#14256, #14264). */
final class ArrayColumnRuntimeShrinkTest extends TestCase
{
    public function testArrayColumnRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayColumnRuntime.php');
        $this->assertStringContainsString('ArrayColumnJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildColumnArray', $runtime);
        $this->assertStringContainsString('columnWithRuntimeKey', $runtime);
        $this->assertStringContainsString('ABI_COLUMN_RUNTIME', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_column.php');
        $this->assertStringContainsString('ArrayColumnRuntime::column', $builtin);
        $this->assertStringContainsString('ArrayColumnRuntime::columnWithRuntimeKey', $builtin);
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

    public function testArrayColumnJitHelperRuntimeKeyMatchesCompileTimePath(): void
    {
        $ht = new HashTable();
        foreach ([['e' => 1], ['e' => 2]] as $i => $row) {
            $rowHt = new HashTable();
            $cell = new Variable();
            $cell->int($row['e']);
            $rowHt->update('e', $cell);
            $rowVar = new Variable();
            $rowVar->array($rowHt);
            $ht->addIndex($i, $rowVar);
        }
        $keyVar = new Variable();
        $keyVar->string('e');
        $runtimeOut = ArrayColumnJitHelper::columnWithRuntimeKey($ht, $keyVar);
        $staticOut = ArrayColumnJitHelper::columnWithKey($ht, 'e');
        $this->assertSame(
            $staticOut->findIndex(0)?->resolveIndirect()->toInt(),
            $runtimeOut->findIndex(0)?->resolveIndirect()->toInt()
        );
        $this->assertSame(
            $staticOut->findIndex(1)?->resolveIndirect()->toInt(),
            $runtimeOut->findIndex(1)?->resolveIndirect()->toInt()
        );
    }
}
