<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: (int)/(float) inline call-arg is sent, not NULL (#32293).
 *
 * @see php-src Zend/zend_vm_def.h ZEND_CAST / ZEND_SEND_VAL
 *
 * @group llvm
 * @group aot
 */
final class CastIntFloatCallArg32293AotTest extends TestCase
{
    private const EXPECT = "int(1)\nfloat(2)\nint(1)\nfloat(2)\n";

    public function testVmCastCallArgMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32293_cast_call_arg.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32293_cast_call_arg.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotCastCallArgMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32293_cast_call_arg.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32293_cast_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
