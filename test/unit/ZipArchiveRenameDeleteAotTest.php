<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ZipArchive renameName / deleteName NestedJIT (#35450 leftover of #35424).
 *
 * @see php-src ext/zip/php_zip.c zim_ZipArchive_renameName / zim_ZipArchive_deleteName
 *
 * @group llvm
 * @group aot
 */
final class ZipArchiveRenameDeleteAotTest extends TestCase
{
    public function testRenameDeleteAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (\extension_loaded('zip')) {
            $this->markTestSkipped('host ext/zip loaded');
        }

        $src = __DIR__.'/../repro/ziparchive_rename_delete_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testProxyRegisteredForRenameDelete(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/zip/Module.php');
        $this->assertStringContainsString("'renameName'", $src);
        $this->assertStringContainsString("'deleteName'", $src);
        $helper = (string) file_get_contents($root.'/ext/zip/ZipArchiveJitHelper.php');
        $this->assertStringContainsString("'rename'", $helper);
        $this->assertStringContainsString("'delete'", $helper);
    }

    private function runVm(string $src): string
    {
        return $this->runEnv(['PHP_COMPILER_ENABLE_ZIP=1'], 'bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/zip_rd_'.getmypid().'_'.md5($src);
        $compile = 'env PHP_COMPILER_ENABLE_ZIP=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile.' 2>&1', $cout, $crc);
            $this->assertSame(0, $crc, implode("\n", $cout));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            @unlink($bin);
            chdir($cwd);
        }
    }

    /**
     * @param list<string> $env
     */
    private function runEnv(array $env, string $binRel, string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env '.implode(' ', $env).' '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/'.$binRel).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }
}
