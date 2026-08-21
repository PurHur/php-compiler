<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplTempFileObject fwrite/fgets on php://temp (#33431).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplTempFileObject___construct
 *
 * @group llvm
 * @group aot
 */
final class SplTempFileObject33431AotTest extends TestCase
{
    private const EXPECTED = "class=SplTempFileObject isa=1 n=12 a='hello\n' b='world\n'\n";

    public function testVmMatchesExpected(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/spltempfileobject_aot.php');
        $this->assertNotFalse($code);
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'spltempfileobject_aot.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotMatchesExpected(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc-stfo-33431-'.getmypid();
        $src = $root.'/test/repro/spltempfileobject_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testHelperMentionsTempConstruct(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileTempConstruct', $helper);
        $this->assertStringContainsString('php://temp', $helper);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString('spltempfileobject::', $ctx);
    }
}
