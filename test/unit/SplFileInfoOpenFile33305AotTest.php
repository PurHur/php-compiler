<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileInfo::openFile → SplFileObject (#33305).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileInfo_openFile
 *
 * @group llvm
 * @group aot
 */
final class SplFileInfoOpenFile33305AotTest extends TestCase
{
    private const EXPECTED =
        "class=SplFileObject\n"
        ."name=a.txt\n"
        ."pn=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt\n";

    public function testVmSplFileInfoOpenFile(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/splfileinfo_openfile_aot.php');
        $this->assertNotFalse($code);
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'splfileinfo_openfile_aot.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSplFileInfoOpenFile(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/splfileinfo_openfile_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33305_'.getmypid().'.bin';
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

    public function testAotSplFileObjectConstructGetFilename(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $code = '<?php $o = new SplFileObject("test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt");'
            .'echo get_class($o), " ", $o->getFilename(), "\n";';
        $tmpSrc = sys_get_temp_dir().'/phpc_issue_33305_ctor_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33305_ctor_'.getmypid().'.bin';
        file_put_contents($tmpSrc, $code);
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($tmpSrc).' 2>&1';
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("SplFileObject a.txt\n", implode("\n", $runOut)."\n");
        } finally {
            chdir($cwd);
            @unlink($bin);
            @unlink($tmpSrc);
        }
    }

    public function testFixtureExists(): void
    {
        $path = dirname(__DIR__).'/fixtures/aot/cases/splfileinfo_openfile.phpt';
        $this->assertFileExists($path);
        $body = file_get_contents($path);
        $this->assertIsString($body);
        $this->assertStringContainsString('#33305', $body);
        $this->assertStringContainsString('openFile', $body);
    }

    public function testNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/spl_fileinfo_openfile.c');
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileinfo_openfile.c');
        $helper = (string) file_get_contents($root.'/lib/VM/DirectoryIteratorJitHelper.php');
        $this->assertStringContainsString('compileOpenFile', $helper);
        $this->assertStringContainsString('#33305', $helper);
        $sfo = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('emitNewFromPathname', $sfo);
        $this->assertStringContainsString('snapshotPath', $sfo);
        $this->assertStringContainsString('compileGetFilename', $sfo);
    }
}
