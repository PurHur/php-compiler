<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT asort()/ksort() must build and match Zend under thin standalone (#27513).
 *
 * Fixed alongside #27227 (HashTable LLVM); this guards the issue repro.
 * php-src: ext/standard/array.c — PHP_FUNCTION(asort) / PHP_FUNCTION(ksort)
 *
 * @group llvm
 * @group aot
 */
final class AsortKsortAot27513Test extends TestCase
{
    public function testAotAsortKsortMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_27513_aot_asort_ksort.php';
        $this->assertFileExists($src);

        $php = escapeshellarg(PHP_BINARY);
        $srcQ = escapeshellarg($src);
        $zend = [];
        $zendRc = 0;
        exec($php.' '.$srcQ.' 2>/dev/null', $zend, $zendRc);
        $this->assertSame(0, $zendRc, 'Zend repro failed');
        $want = implode("\n", $zend)."\n";
        $this->assertSame("a,b,c|1,2,3\na,b,c|1,2,3\n", $want);

        $vm = [];
        $vmRc = 0;
        exec($php.' '.escapeshellarg($root.'/bin/vm.php').' '.$srcQ.' 2>/dev/null', $vm, $vmRc);
        $this->assertSame(0, $vmRc, 'VM repro failed');
        $this->assertSame($want, implode("\n", $vm)."\n");

        $bin = sys_get_temp_dir().'/phpc_asort_ksort_27513_'.getmypid().'.bin';
        @unlink($bin);
        $compile = $php.' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.$srcQ.' 2>&1';
        $compileOut = [];
        $compileRc = 0;
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $runText = implode("\n", $runOut)."\n";
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.$runText);
                $this->assertSame($want, $runText, 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
