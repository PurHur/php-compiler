<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NaturalSortJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** natsort()/natcasesort() JIT routes through NaturalSortJitHelper PHP not ArrayBuiltinHelper LLVM (#12753). */
final class NaturalSortRuntimeShrinkTest extends TestCase
{
    public function testNaturalSortRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/NaturalSortRuntime.php');
        $this->assertStringContainsString('NaturalSortJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::natsortByValue', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $natsort = (string) file_get_contents(__DIR__.'/../../ext/standard/natsort_.php');
        $natcasesort = (string) file_get_contents(__DIR__.'/../../ext/standard/natcasesort_.php');
        $this->assertStringContainsString('NaturalSortRuntime::natsortByValue', $natsort);
        $this->assertStringContainsString('NaturalSortRuntime::natcasesortByValue', $natcasesort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::natsortByValue', $natsort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::natcasesortByValue', $natcasesort);
    }

    public function testNaturalSortJitHelperMatchesVmNatsortSemantics(): void
    {
        $ht = self::listTable('IMG12', 'img2', 'Img1');
        NaturalSortJitHelper::natsortByValue($ht);
        $this->assertSame(['IMG12', 'Img1', 'img2'], self::valuesInOrder($ht));
    }

    public function testNaturalSortJitHelperMatchesVmNatcasesortSemantics(): void
    {
        $ht = self::listTable('IMG12', 'img2', 'Img1');
        NaturalSortJitHelper::natcasesortByValue($ht);
        $this->assertSame(['Img1', 'img2', 'IMG12'], self::valuesInOrder($ht));
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

    /** @return list<string> */
    private static function valuesInOrder(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterate(true) as $value) {
            $out[] = $value->resolveIndirect()->toString();
        }

        return $out;
    }
}
