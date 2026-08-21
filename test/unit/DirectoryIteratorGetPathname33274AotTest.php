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
    private const FIXTURE = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';

    private const EXPECTED = 'name=a.txt path='.self::FIXTURE
        .' pathname='.self::FIXTURE.'/a.txt str=a.txt str_is_name=1 pathname_ends=1'."\n";

    private function rewrittenSource(): string
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/directoryiterator_getpathname_aot_33274.php');
        $this->assertNotFalse($code);

        return str_replace(
            "__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture'",
            "'".self::FIXTURE."'",
            $code
        );
    }

    public function testVmDirectoryIteratorPathnameAccessors(): void
    {
        $runtime = new Runtime();
        $code = $this->rewrittenSource();
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'directoryiterator_getpathname_aot_33274.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDirectoryIteratorPathnameAccessors(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_issue_33274_src_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33274_'.getmypid().'.bin';
        file_put_contents($src, $this->rewrittenSource());
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
            @unlink($src);
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
