<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::getCurrentLine aliases fgets (#33321).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_getCurrentLine
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectGetCurrentLine33321AotTest extends TestCase
{
    private const EXPECTED =
        "gcl=\"line1\\n\"\n"
        ."cur=\"line1\\n\"\n"
        ."gcl2=\"line2\\n\"\n";

    public function testVmSplFileObjectGetCurrentLine(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/splfileobject_getcurrentline_aot.php');
        $this->assertNotFalse($code);
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'splfileobject_getcurrentline_aot.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSplFileObjectGetCurrentLine(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/splfileobject_getcurrentline_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33321_gcl_'.getmypid().'.bin';
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
        }
    }

    public function testProxyWiresGetCurrentLine(): void
    {
        $root = dirname(__DIR__, 2);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fgets', 'getcurrentline'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fgets', 'getCurrentLine', 'fwrite', 'eof'", $ctx);
        $this->assertStringContainsString('#33321', $ctx);
    }
}
