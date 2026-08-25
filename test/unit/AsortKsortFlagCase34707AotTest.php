<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: asort()/arsort()/ksort()/krsort() honour SORT_STRING|SORT_FLAG_CASE (#34707).
 *
 * @group llvm
 * @group aot
 */
final class AsortKsortFlagCase34707AotTest extends TestCase
{
    public function testAotAsortKsortStringFlagCaseMatchZend(): void
    {
        $src = __DIR__.'/../repro/issue_34707_asort_ksort_flag_case_aot.php';
        $zend = $this->runPhp($src);
        $this->assertSame(
            "asort_case:[\"a\",\"B\",\"C\"]\n"
            ."arsort_case:[\"C\",\"B\",\"a\"]\n"
            ."ksort_case:[\"a\",\"B\",\"C\"]\n"
            ."krsort_case:[\"C\",\"B\",\"a\"]\n"
            ."asort_regular:[\"B\",\"C\",\"a\"]\n"
            ."ksort_regular:[\"B\",\"C\",\"a\"]",
            $zend
        );
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    public function testDispatchWiresCaseAbi(): void
    {
        $asort = (string) file_get_contents(__DIR__.'/../../ext/standard/asort_.php');
        $this->assertStringContainsString('asortByValueCase', $asort);
        $this->assertStringContainsString('#34707', $asort);
        $ksort = (string) file_get_contents(__DIR__.'/../../ext/standard/ksort_.php');
        $this->assertStringContainsString('ksortByKeyCase', $ksort);
        $ht = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('__hashtable__sortStringKeysCase', $ht);
        $this->assertStringContainsString('__hashtable__sortStringKeysReverseCase', $ht);
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
