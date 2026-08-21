<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DirectoryIterator SplFileInfo stat accessors (#33283).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileInfo_getMTime
 *
 * @group llvm
 * @group aot
 */
final class DirectoryIteratorStatAccessors33283AotTest extends TestCase
{
    private const EXPECTED =
        'name=a.txt perms=100644 mtime_ok=1 atime_ok=1 ctime_ok=1'
        ." owner_ok=1 group_ok=1 inode_ok=1\n";

    public function testVmDirectoryIteratorStatAccessors(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/directoryiterator_stat_accessors_aot.php');
        $this->assertNotFalse($code);
        $code = str_replace(
            "__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture'",
            "'test/fixtures/aot/cases/directoryiterator_27289_fixture'",
            $code
        );
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'directoryiterator_stat_accessors_aot.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDirectoryIteratorStatAccessors(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $code = file_get_contents($root.'/test/repro/directoryiterator_stat_accessors_aot.php');
        $this->assertNotFalse($code);
        $code = str_replace(
            "__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture'",
            "'test/fixtures/aot/cases/directoryiterator_27289_fixture'",
            $code
        );
        $tmpSrc = sys_get_temp_dir().'/phpc_issue_33283_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33283_'.getmypid().'.bin';
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
        $path = dirname(__DIR__).'/fixtures/aot/cases/directoryiterator_stat_accessors.phpt';
        $this->assertFileExists($path);
        $body = file_get_contents($path);
        $this->assertIsString($body);
        $this->assertStringContainsString('#33283', $body);
        $this->assertStringContainsString('getMTime', $body);
    }
}
