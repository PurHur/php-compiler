<?php

declare(strict_types=1);

use PHPCompiler\ext\standard\StdlibConstants;
use PHPUnit\Framework\TestCase;

/**
 * AOT: asort/arsort/ksort/krsort honour SORT_STRING|SORT_FLAG_CASE (#34707).
 *
 * @group llvm
 * @group aot
 */
final class AsortKsortStringFlagCase34707AotTest extends TestCase
{
    public function testAotAsortKsortStringFlagCaseMatchZend(): void
    {
        $src = __DIR__.'/../repro/issue_34707_asort_ksort_flag_case_aot.php';
        $zend = $this->runPhp($src);
        $this->assertSame(
            "[\"a\",\"B\",\"C\"]\n[\"C\",\"B\",\"a\"]\n[\"a\",\"B\",\"C\"]\n[\"C\",\"B\",\"a\"]",
            $zend
        );
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    public function testRuntimeWiresStringCaseBridges(): void
    {
        $valueRt = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueSortRuntime.php');
        $this->assertStringContainsString('asortByValueStringCase', $valueRt);
        $this->assertStringContainsString('arsortByValueStringCase', $valueRt);
        $keyRt = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/KeySortRuntime.php');
        $this->assertStringContainsString('ksortByKeyStringCase', $keyRt);
        $this->assertStringContainsString('krsortByKeyStringCase', $keyRt);
        $this->assertStringContainsString('__hashtable__sortStringKeysCase', $keyRt);
        $ht = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('__hashtable__sortStringKeysCase', $ht);
        $this->assertStringContainsString('__hashtable__sortStringKeysReverseCase', $ht);
        $this->assertStringContainsString('#34707', (string) file_get_contents(__DIR__.'/../../ext/standard/asort_.php'));
        $this->assertStringContainsString('#34707', (string) file_get_contents(__DIR__.'/../../ext/standard/ksort_.php'));
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
        $bin = sys_get_temp_dir().'/asort_34707_'.getmypid().'_'.md5($src);
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
}
