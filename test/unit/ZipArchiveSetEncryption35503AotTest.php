<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ZipArchive::setEncryptionName/setEncryptionIndex NestedJIT (#35503 leftover of #35500).
 *
 * @see php-src ext/zip/php_zip.c zim_ZipArchive_setEncryptionName / zim_ZipArchive_setEncryptionIndex
 *
 * @group llvm
 * @group aot
 */
final class ZipArchiveSetEncryption35503AotTest extends TestCase
{
    public function testSetEncryptionAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (\extension_loaded('zip')) {
            $this->markTestSkipped('host ext/zip loaded');
        }

        $src = __DIR__.'/../repro/ziparchive_setencryption_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testProxyAndHelperOpPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/zip/Module.php');
        $this->assertStringContainsString("'setEncryptionName'", $src);
        $this->assertStringContainsString("'setEncryptionIndex'", $src);
        $helper = (string) file_get_contents($root.'/ext/zip/ZipArchiveJitHelper.php');
        $this->assertStringContainsString("'sei'", $helper);
        $this->assertStringContainsString("'seip'", $helper);
        $this->assertStringContainsString('$h1enc', $helper);
        $jit = (string) file_get_contents($root.'/ext/zip/JitZipArchive.php');
        $this->assertStringContainsString('function setEncryptionName', $jit);
        $this->assertStringContainsString('function setEncryptionIndex', $jit);
        $this->assertStringContainsString("'locate'", $jit);
    }

    private function runVm(string $src): string
    {
        return $this->runEnv(['PHP_COMPILER_ENABLE_ZIP=1'], 'bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/zip_sen_'.getmypid().'_'.md5($src);
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
