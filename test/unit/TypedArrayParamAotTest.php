<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * AOT: typed `array` formals must not delref-free the caller's HT (#36386).
 *
 * @see php-src Zend/zend_execute_API.c zend_get_parameters_array_ex
 * @see php-src Zend/zend_hash.c object handles stay shared across by-value arrays
 *
 * @group llvm
 * @group aot
 */
final class TypedArrayParamAotTest extends TestCase
{
    private const EXPECTED = "2:5:5\n3\n";

    public function testAotTypedArrayParamMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/i36386_typed_array_param.php';
        $bin = sys_get_temp_dir().'/phpc_i36386_typed_array_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $zendOut = [];
            $zendRc = 0;
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $this->assertSame(self::EXPECTED, implode("\n", $zendOut)."\n");

            $matched = 0;
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n", 'run '.($i + 1));
                ++$matched;
            }
            $this->assertSame(5, $matched);
        } finally {
            @unlink($bin);
        }
    }
}
