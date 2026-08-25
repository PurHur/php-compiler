<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: finfo_file / finfo::file(data://) matches Zend (#34797 / peer #34789).
 *
 * @see php-src ext/fileinfo/fileinfo.c
 * @see php-src ext/standard/php_data_wrapper.c
 *
 * @group llvm
 * @group aot
 */
final class FinfoFileDataUri34797AotTest extends TestCase
{
    private const EXPECT = "method:'application/octet-stream'\nproc:'application/octet-stream'\nbase64:'application/octet-stream'\nfs:'text/plain'\n";

    public function testHelperDecodesDataUriBeforeIsReadable(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/fileinfo/FinfoFileJitHelper.php');
        $this->assertStringContainsString('#34797', $src);
        $this->assertStringContainsString("substr(\$path, 0, 5)", $src);
        $this->assertStringContainsString('decodeDataUri', $src);
        $posData = strpos($src, "'data:' ===");
        $posReadable = strpos($src, 'is_readable($path)');
        $this->assertNotFalse($posData);
        $this->assertNotFalse($posReadable);
        $this->assertLessThan($posReadable, $posData, 'data: must skip is_readable (#34797)');
    }

    public function testRuntimeEnsuresBase64ForNestedJit(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FinfoFileRuntime.php');
        $this->assertStringContainsString('StringBase64Decode::ensureLinked', $src);
        $this->assertStringContainsString('#34797', $src);
    }

    public function testVmFinfoFileDataUri(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34797_finfo_file_data_uri_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34797_finfo_file_data_uri_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotFinfoFileDataUri(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34797_finfo_file_data_uri_aot.php';
        $bin = sys_get_temp_dir().'/phpc_aot_finfo_34797_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
