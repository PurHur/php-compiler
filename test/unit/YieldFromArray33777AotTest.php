<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: yield from array/string must compile (#33777).
 *
 * php-src: Zend/zend_generators.c — yield from / zend_generator_get_next_delegated_value
 *
 * @group llvm
 * @group aot
 */
final class YieldFromArray33777AotTest extends TestCase
{
    private function compile(string $srcRelative): string
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.$srcRelative;
        $bin = sys_get_temp_dir().'/phpc_yield_from_33777_'.getmypid().'_'.md5($srcRelative).'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        return $bin;
    }

    public function testAotYieldFromArrayMatchesZend(): void
    {
        $bin = $this->compile('test/repro/issue_33777_yield_from_array_aot.php');
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("3,2\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testAotYieldFromStringErrorsLikeZend(): void
    {
        $bin = $this->compile('test/repro/issue_33777_yield_from_string_aot.php');
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = implode("\n", $runOut);
            // Thin AOT ErrorRaise surfaces as uncaught Error; message must match Zend.
            $this->assertStringContainsString(
                'Can use "yield from" only with arrays and Traversables',
                $joined
            );
            $this->assertNotSame(0, $runRc);
        } finally {
            @unlink($bin);
        }
    }
}
