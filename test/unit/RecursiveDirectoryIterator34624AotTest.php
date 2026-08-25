<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: RecursiveDirectoryIterator construct + foreach (#34624).
 *
 * @see php-src ext/spl/spl_directory.c RecursiveDirectoryIterator
 *
 * @group llvm
 * @group aot
 */
final class RecursiveDirectoryIterator34624AotTest extends TestCase
{
    private const EXPECTED = "rdi:a.txt\nrii:a.txt\nrii_ok:1\n";

    public function testContextRegistersRdiProxies(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'RecursiveDirectoryIterator'", $ctx);
        $this->assertStringContainsString(
            "['DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator']",
            $ctx
        );
    }

    public function testObjectLayoutParentsFilesystemIterator(): void
    {
        $obj = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString("'recursivedirectoryiterator' === \$lcname", $obj);
        $this->assertStringContainsString("setClassParentName(\$displayName, 'FilesystemIterator')", $obj);
    }

    public function testVmRecursiveDirectoryIteratorFixture(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/recursivedirectoryiterator_aot_34624.php');
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
            $runtime->run($runtime->parseAndCompile($code, 'recursivedirectoryiterator_aot_34624.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotRecursiveDirectoryIteratorFixture(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/recursivedirectoryiterator_aot_34624.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34624_'.getmypid().'.bin';
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
}
