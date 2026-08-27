<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: ZipArchive::locateName / getFromIndex after CREATE roundtrip (#35437).
 *
 * @group llvm
 * @group aot
 */
final class ZipArchiveLocateGetFromIndex35437AotTest extends TestCase
{
    public function testLocateNameAndGetFromIndexMatchVm(): void
    {
        $src = __DIR__.'/../repro/ziparchive_locate_getfromindex_aot.php';
        $env = 'PHP_COMPILER_ENABLE_ZIP=1 ';
        $vm = $this->runCmd($env.escapeshellarg(PHP_BINARY).' '.escapeshellarg(
            dirname(__DIR__, 2).'/bin/vm.php'
        ).' '.escapeshellarg($src));
        $aot = $this->runAot($src);
        $this->assertSame($vm, $aot);
        $this->assertStringContainsString("0\n", $aot);
        $this->assertStringContainsString("'hello'", $aot);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/zip_35437_'.getmypid().'_'.mt_rand();
        $comp = 'PHP_COMPILER_ENABLE_ZIP=1 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($comp.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        $out = $this->runCmd(escapeshellarg($bin));
        @unlink($bin);

        return $out;
    }

    private function runCmd(string $cmd): string
    {
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
