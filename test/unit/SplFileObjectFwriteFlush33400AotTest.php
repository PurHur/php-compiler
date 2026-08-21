<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fwrite/fputcsv flush so path reads match Zend (#33400).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fwrite / fputcsv
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFwriteFlush33400AotTest extends TestCase
{
    private const EXPECTED = "alive=[hi] n=2\nafter=[hi]\ncsv=[a,b\\n]\n";

    public function testVmMatchesExpected(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/splfileobject_fwrite_flush_aot.php');
        $this->assertNotFalse($code);
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'splfileobject_fwrite_flush_aot.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotMatchesExpected(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $tmpSrc = sys_get_temp_dir().'/phpc_issue_33400_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33400_'.getmypid().'.bin';
        copy($root.'/test/repro/splfileobject_fwrite_flush_aot.php', $tmpSrc);
        // Prefer committed helper cache (StreamMethods33318 pattern) — O=0 + empty
        // cache has been segfaulting unrelated fflush AOT fixtures in this harness.
        $compile = escapeshellarg(PHP_BINARY).' '
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

    public function testHelperFlushesAfterWrite(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('__compiler_fflush', $helper);
        $this->assertStringContainsString('#33400', $helper);
        $this->assertStringContainsString('compileFwrite', $helper);
        $this->assertStringContainsString('compileFputcsv', $helper);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fwrite_flush.c');
    }
}
