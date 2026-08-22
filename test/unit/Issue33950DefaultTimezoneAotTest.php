<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT date_default_timezone_get/set NestedJIT coerce (#33950).
 *
 * @group llvm
 * @group aot
 */
final class Issue33950DefaultTimezoneAotTest extends TestCase
{
    public function testRuntimeUsesNestedHelperCoerce(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/DefaultTimezoneRuntime.php'
        );
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $source);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $source);
        $this->assertStringContainsString('extractBoolFromHelperResult', $source);
        $this->assertStringContainsString('#33950', $source);
    }

    public function testHelperOwnsDefaultTimezoneStatic(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/DefaultTimezoneJitHelper.php'
        );
        $this->assertStringContainsString('private static string $defaultTimezone', $source);
        $this->assertStringContainsString('copyTimezoneId', $source);
        $this->assertStringContainsString('#33950', $source);
    }

    public function testAotGetSetMatchZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33950_default_timezone_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dtz_33950_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>/dev/null', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expected = implode("\n", $zendOut)."\n";

        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
