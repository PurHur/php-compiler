<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NaturalCompareJitHelper;
use PHPCompiler\ext\standard\NaturalSortJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * natsort()/natcasesort() JIT/AOT uses LLVM `__hashtable__sortPackedNatural*` (#26975);
 * NaturalSortJitHelper remains Zend-hosted SSOT for unit tests.
 * strnatcmp NestedJIT must not call VmString (external stub → 0 under thin AOT).
 */
final class NaturalSortRuntimeShrinkTest extends TestCase
{
    public function testNaturalSortRuntimeUsesLlvmPackedNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/NaturalSortRuntime.php');
        $this->assertStringContainsString('__hashtable__sortPackedNatural', $runtime);
        $this->assertStringContainsString('__hashtable__sortStringKeyValuesNatural', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('NaturalSortJitHelper::natsortByValue', $runtime);

        $hashTableType = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('implementSortPackedNatural', $hashTableType);
        $this->assertStringContainsString("'__hashtable__sortPackedNatural'", $hashTableType);

        $natsort = (string) file_get_contents(__DIR__.'/../../ext/standard/natsort_.php');
        $natcasesort = (string) file_get_contents(__DIR__.'/../../ext/standard/natcasesort_.php');
        $asort = (string) file_get_contents(__DIR__.'/../../ext/standard/asort_.php');
        $this->assertStringContainsString('NaturalSortRuntime::natsortByValue', $natsort);
        $this->assertStringContainsString('NaturalSortRuntime::natcasesortByValue', $natcasesort);
        $this->assertStringContainsString('NaturalSortRuntime::natsortByValue', $asort);
        $this->assertStringContainsString('NaturalSortRuntime::natcasesortByValue', $asort);
        $this->assertStringNotContainsString(
            'asort() flags are not supported in JIT/AOT',
            $asort
        );
    }

    public function testNaturalCompareJitHelperDoesNotCallVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/NaturalCompareJitHelper.php');
        // Docblock may @see VmString; the call itself must stay local (#26975 NestedJIT stub).
        $this->assertStringNotContainsString('VmString::strnatcmp(', $source);
        $this->assertStringNotContainsString('VmString::strnatcasecmp(', $source);
        $this->assertStringContainsString('strlen', $source);
        $this->assertSame(-1, NaturalCompareJitHelper::strnatcmpArgv('img2', 'img10'));
        $this->assertSame(1, NaturalCompareJitHelper::strnatcmpArgv('img10', 'img2'));
        $this->assertSame(0, NaturalCompareJitHelper::strnatcmpArgv('a', 'a'));
        $this->assertSame(VmString::strnatcmp('img2', 'img10'), NaturalCompareJitHelper::strnatcmpArgv('img2', 'img10'));
        $this->assertSame(VmString::strnatcasecmp('A2', 'a10'), NaturalCompareJitHelper::strnatcasecmpArgv('A2', 'a10'));
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
