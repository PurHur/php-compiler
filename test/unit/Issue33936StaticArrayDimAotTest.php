<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT dim fetch on static array — no CFG JUMP after static fetch (#33936).
 *
 * @group llvm
 * @group aot
 */
final class Issue33936StaticArrayDimAotTest extends TestCase
{
    public function testNeedsCfgSplitSkipsStaticPropertyDimContainer(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/Compiler.php'
        );
        $this->assertStringContainsString('#33936', $source);
        $this->assertStringContainsString('StaticPropertyFetch', $source);
        $this->assertStringContainsString('needsCfgSplitBeforeStringDimFetch', $source);
    }

    public function testAotStaticArrayDimMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33936_static_array_dim_aot.php';
        $bin = sys_get_temp_dir().'/phpc_static_dim_33936_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $expected = "1\n1\n1:1\n1\n1\n9:1\n";
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
