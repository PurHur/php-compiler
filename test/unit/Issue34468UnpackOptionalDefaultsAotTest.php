<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT unpack missing optional params must use Native defaults, not null (#34468 follow-up).
 *
 * php-src: Zend/zend_execute.c — ZEND_SEND_UNPACK + default arg binding
 *
 * @group llvm
 * @group aot
 */
final class Issue34468UnpackOptionalDefaultsAotTest extends TestCase
{
    public function testExpandUsesDefaultOnMiss(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/CallUnpackExpand.php');
        $this->assertStringContainsString('defaultOnMiss', $source);
        $this->assertStringContainsString('defaultArgs', $source);
        $this->assertStringContainsString('#34468', $source);
    }

    public function testAotUnpackOptionalDefaultsMatchZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34468_unpack_optional_defaults.php';
        $bin = sys_get_temp_dir().'/phpc_unpack_opt_34468_'.getmypid().'.bin';
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
