<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DirectoryIterator getPathname/getPath/__toString (#33274).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileInfo_getPathname
 *
 * @group llvm
 * @group aot
 */
final class DirectoryIteratorGetPathname33274AotTest extends TestCase
{
    private const EXPECTED =
        'name=a.txt path=test/fixtures/aot/cases/directoryiterator_27289_fixture'
        .' pathname=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt'
        ." str=a.txt\n";

    public function testVmDirectoryIteratorPathAccessors(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/directoryiterator_getpathname_aot.php');
        $this->assertNotFalse($code);
        // parseAndCompile() uses a basename script name, so __DIR__ is "."; use repo-relative path.
        $code = str_replace(
            "__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture'",
            "'test/fixtures/aot/cases/directoryiterator_27289_fixture'",
            $code
        );
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'directoryiterator_getpathname_aot.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDirectoryIteratorPathAccessors(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/directoryiterator_getpathname_aot.php';
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        // Compile with repo-relative path so getPath matches the fixture expectation.
        $code = str_replace(
            "__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture'",
            "'test/fixtures/aot/cases/directoryiterator_27289_fixture'",
            $code
        );
        $tmpSrc = sys_get_temp_dir().'/phpc_issue_33274_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33274_'.getmypid().'.bin';
        file_put_contents($tmpSrc, $code);
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($tmpSrc).' 2>&1';
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
            @unlink($tmpSrc);
        }
    }

    public function testFixtureExists(): void
    {
        $path = dirname(__DIR__).'/fixtures/aot/cases/directoryiterator_getpathname.phpt';
        $this->assertFileExists($path);
        $body = file_get_contents($path);
        $this->assertIsString($body);
        $this->assertStringContainsString('#33274', $body);
        $this->assertStringContainsString('getPathname', $body);
    }
}
