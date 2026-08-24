<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT `new C(...$a)` must expand unpack after the $this receiver prefix (#34468).
 *
 * php-src: Zend/zend_execute.c — ZEND_NEW / ZEND_SEND_UNPACK
 *
 * @group llvm
 * @group aot
 */
final class Issue34468NewUnpackArgsAotTest extends TestCase
{
    public function testUnpackPathPreservesReceiverPrefix(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#34468', $source);
        $this->assertStringContainsString('prependJitCallArgPrefix', $source);
        $this->assertStringContainsString(
            'Keep $this / receiver prefix out of the packed HT',
            $source
        );
    }

    public function testAotNewUnpackMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34468_new_unpack_args.php';
        $bin = sys_get_temp_dir().'/phpc_new_unpack_34468_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        $compileOut = [];
        $compileRc = 0;
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expect = implode("\n", $zendOut)."\n";

        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expect, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
