<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileInfo::getFileInfo / getPathInfo (#33300).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileInfo_getFileInfo / getPathInfo
 *
 * @group llvm
 * @group aot
 */
final class SplFileInfoGetPathInfo33300AotTest extends TestCase
{
    private const EXPECTED =
        "fi_class=SplFileInfo\n"
        ."fi_name=a.txt\n"
        ."fi_pn=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt\n"
        ."pi_class=SplFileInfo\n"
        ."pi_name=directoryiterator_27289_fixture\n"
        ."pi_pn=test/fixtures/aot/cases/directoryiterator_27289_fixture\n";

    public function testVmSplFileInfoGetPathInfo(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/splfileinfo_getpathinfo_aot.php');
        $this->assertNotFalse($code);
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'splfileinfo_getpathinfo_aot.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSplFileInfoGetPathInfo(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/splfileinfo_getpathinfo_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33300_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }

    public function testFixtureExists(): void
    {
        $path = dirname(__DIR__).'/fixtures/aot/cases/splfileinfo_getpathinfo.phpt';
        $this->assertFileExists($path);
        $body = file_get_contents($path);
        $this->assertIsString($body);
        $this->assertStringContainsString('#33300', $body);
        $this->assertStringContainsString('getPathInfo', $body);
    }

    public function testNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/spl_fileinfo_pathinfo.c');
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileinfo_pathinfo.c');
        $helper = (string) file_get_contents($root.'/lib/VM/DirectoryIteratorJitHelper.php');
        $this->assertStringContainsString('compileGetPathInfo', $helper);
        $this->assertStringContainsString('compileGetFileInfo', $helper);
        $this->assertStringContainsString('#33300', $helper);
    }
}
