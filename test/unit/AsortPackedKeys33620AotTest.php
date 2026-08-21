<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: asort()/arsort() on packed lists must preserve keys (#33620).
 *
 * @group llvm
 * @group aot
 */
final class AsortPackedKeys33620AotTest extends TestCase
{
    public function testPackedAsortArsortMatchZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33620_asort_packed_keys.php');
    }

    public function testValueSortRuntimeRoutesListsThroughKeyedLlvm(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/ValueSortRuntime.php');
        $this->assertStringContainsString('ValueSortKeyedLlvm::sortValuesPreserveKeys', $runtime);
        $this->assertStringContainsString('#33620', $runtime);
        $this->assertStringNotContainsString('JitArrayIsList::invoke', $runtime);
        $llvm = (string) file_get_contents($root.'/lib/JIT/ValueSortKeyedLlvm.php');
        $this->assertStringContainsString('reorderKeyedPairs', $llvm);
        $this->assertStringContainsString('HashTableExportKeyValuePairs', $llvm);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $this->assertSame("1a0b\n1b0a\nyaxb\n", $zend."\n");
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
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
        $bin = sys_get_temp_dir().'/asort_33620_'.getmypid().'_'.md5($src);
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
