<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DirectoryIterator isLink/isReadable/isWritable/isExecutable (#33269).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileInfo_isLink
 *
 * @group llvm
 * @group aot
 */
final class DirectoryIteratorIsLink33269AotTest extends TestCase
{
    private const EXPECTED = "name=a.txt link=0 read=1 write=1\n";

    public function testVmDirectoryIteratorPathPredicates(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/directoryiterator_islink_aot_33269.php');
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
            $runtime->run($runtime->parseAndCompile($code, 'directoryiterator_islink_aot_33269.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDirectoryIteratorPathPredicates(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/directoryiterator_islink_aot_33269.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33269_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testFixtureExists(): void
    {
        $path = dirname(__DIR__).'/fixtures/aot/cases/directoryiterator_islink_preds.phpt';
        $this->assertFileExists($path);
        $body = file_get_contents($path);
        $this->assertIsString($body);
        $this->assertStringContainsString('#33269', $body);
        $this->assertStringContainsString('isLink', $body);
    }
}
