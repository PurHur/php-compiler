<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject inherited SplFileInfo stat methods (#33313).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileInfo_isFile / getSize / getExtension / getType
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectStatMethods33313AotTest extends TestCase
{
    private const EXPECTED =
        "isFile=1\n"
        ."size=2\n"
        ."ext=txt\n"
        ."type=file\n";

    public function testVmSplFileObjectStatMethods(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/splfileobject_stat_methods_aot.php');
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
            $runtime->run($runtime->parseAndCompile($code, 'splfileobject_stat_methods_aot.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSplFileObjectStatMethods(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $code = file_get_contents($root.'/test/repro/splfileobject_stat_methods_aot.php');
        $this->assertNotFalse($code);
        $code = str_replace(
            "__DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture/a.txt'",
            "'test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt'",
            $code
        );
        $tmpSrc = sys_get_temp_dir().'/phpc_issue_33313_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33313_'.getmypid().'.bin';
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
        $path = dirname(__DIR__).'/fixtures/aot/cases/splfileobject_stat_methods.phpt';
        $this->assertFileExists($path);
        $body = file_get_contents($path);
        $this->assertIsString($body);
        $this->assertStringContainsString('#33313', $body);
        $this->assertStringContainsString('isFile', $body);
    }

    public function testNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_stat_methods.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('initSplFileInfoPathProps', $helper);
        $this->assertStringContainsString('#33313', $helper);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString('Inherited SplFileInfo metadata methods', $ctx);
    }
}
