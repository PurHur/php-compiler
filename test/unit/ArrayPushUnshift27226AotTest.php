<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_push()/array_unshift() bridge LLVM must stay in __array_push__* / __array_unshift__*
 * functions — not cross-function BB/arg refs (#27226 regression).
 *
 * @see php-src ext/standard/array.c — PHP_FUNCTION(array_push / array_unshift)
 *
 * @group llvm
 * @group aot
 */
final class ArrayPushUnshift27226AotTest extends TestCase
{
    private const EXPECT = "3 1,2,3\n4 0,1,2,3\n";

    public function testBridgeRuntimesScopeLoweringToBridgeFunction(): void
    {
        $push = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayPushRuntime.php');
        $unshift = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayUnshiftRuntime.php');
        $this->assertStringContainsString('scopeLoweringToFunction', $push);
        $this->assertStringContainsString('scopeLoweringToFunction', $unshift);
        $this->assertStringContainsString('__array_push__append', $push);
        $this->assertStringContainsString('__array_unshift__prepend', $unshift);
    }

    public function testVmArrayPushUnshift(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_array_push_unshift_27226.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_array_push_unshift_27226.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotArrayPushUnshift(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_array_push_unshift_27226.php';
        $bin = sys_get_temp_dir().'/phpc_aot_push_unshift_27226_'.getmypid().'.bin';
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
