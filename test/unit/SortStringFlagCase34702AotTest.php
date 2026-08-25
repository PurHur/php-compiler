<?php

declare(strict_types=1);

use PHPCompiler\ext\standard\SortJitHelper;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * AOT: sort()/rsort() honour SORT_STRING|SORT_FLAG_CASE (#34702).
 *
 * @group llvm
 * @group aot
 */
final class SortStringFlagCase34702AotTest extends TestCase
{
    public function testAotSortRsortStringFlagCaseMatchZend(): void
    {
        $src = __DIR__.'/../repro/issue_34702_sort_flag_case_aot.php';
        $zend = $this->runPhp($src);
        $this->assertSame(
            "[\"a\",\"B\",\"C\"]\n[\"C\",\"B\",\"a\"]\n[\"B\",\"C\",\"a\"]\n[\"a\",\"C\",\"B\"]",
            $zend
        );
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    public function testSortJitHelperStringCaseMatchesStrcasecmpOrder(): void
    {
        $ht = self::stringListTable('B', 'a', 'C');
        SortJitHelper::sortPackedStringCase($ht);
        $this->assertSame(['a', 'B', 'C'], self::stringValuesInOrder($ht));

        $rev = self::stringListTable('B', 'a', 'C');
        SortJitHelper::sortPackedReverseStringCase($rev);
        $this->assertSame(['C', 'B', 'a'], self::stringValuesInOrder($rev));
    }

    public function testSortRuntimeWiresStringCaseBridges(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SortRuntime.php');
        $this->assertStringContainsString('sortPackedStringCase', $runtime);
        $this->assertStringContainsString('sortPackedReverseStringCase', $runtime);
        $this->assertStringContainsString('__hashtable__sortPackedStringCase', $runtime);
        $ht = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('__hashtable__sortPackedStringCase', $ht);
        $this->assertStringContainsString('#34702', (string) file_get_contents(__DIR__.'/../../ext/standard/sort_.php'));
        $this->assertSame(8, StdlibConstants::SORT_FLAG_CASE);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/sort_34702_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }

    /** @param list<string> $values */
    private static function stringListTable(string ...$values): HashTable
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
    private static function stringValuesInOrder(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterate(true) as $value) {
            $out[] = $value->resolveIndirect()->toString();
        }

        return $out;
    }
}
