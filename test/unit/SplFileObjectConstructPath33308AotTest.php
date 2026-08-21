<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::__construct path props (#33308).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject___construct
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectConstructPath33308AotTest extends TestCase
{
    private const EXPECTED =
        "class=SplFileObject\n"
        ."name=a.txt\n"
        ."path=test/fixtures/aot/cases/directoryiterator_27289_fixture\n"
        ."pn=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt\n";

    public function testVmSplFileObjectConstructPath(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/splfileobject_construct_path_aot.php');
        $this->assertNotFalse($code);
        $code = str_replace(
            "__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture/a.txt'",
            "'test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt'",
            $code
        );
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'splfileobject_construct_path_aot.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSplFileObjectConstructPath(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $code = file_get_contents($root.'/test/repro/splfileobject_construct_path_aot.php');
        $this->assertNotFalse($code);
        $code = str_replace(
            "__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture/a.txt'",
            "'test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt'",
            $code
        );
        $tmpSrc = sys_get_temp_dir().'/phpc_issue_33308_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33308_'.getmypid().'.bin';
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
        $path = dirname(__DIR__).'/fixtures/aot/cases/splfileobject_construct_path.phpt';
        $this->assertFileExists($path);
        $body = file_get_contents($path);
        $this->assertIsString($body);
        $this->assertStringContainsString('#33308', $body);
        $this->assertStringContainsString('SplFileObject', $body);
    }

    public function testNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_path_init.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('initSplFileInfoPathProps', $helper);
        $this->assertStringContainsString('#33308', $helper);
    }
}
