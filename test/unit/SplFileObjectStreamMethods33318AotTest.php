<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject fgets/fwrite/eof via live stream handle (#33318).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fgets / fwrite / eof
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectStreamMethods33318AotTest extends TestCase
{
    private const EXPECTED = "fgets-ok\nfwrite-ok\n";

    public function testVmSplFileObjectStreamMethods(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/spl_fileobject_stream_methods.php');
        $this->assertNotFalse($code);
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'spl_fileobject_stream_methods.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSplFileObjectStreamMethods(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $tmpSrc = sys_get_temp_dir().'/phpc_issue_33318_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33318_'.getmypid().'.bin';
        copy($root.'/test/repro/spl_fileobject_stream_methods.php', $tmpSrc);
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

    public function testHelperWiresStreamAbis(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('PROP_FD', $helper);
        $this->assertStringContainsString('compileFgets', $helper);
        $this->assertStringContainsString('compileFwrite', $helper);
        $this->assertStringContainsString('__compiler_fopen', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fgets'", $call);
        $this->assertStringContainsString("'fwrite'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fgets', 'fwrite', 'eof'", $ctx);
    }
}
