<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for array_intersect_ukey() (#27228).
 *
 * @group llvm
 * @group aot
 */
final class ArrayIntersectUkeyAot27228Test extends TestCase
{
    public function testAotArrayIntersectUkeyClosureMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_array_intersect_ukey_27228.php';
        $bin = sys_get_temp_dir().'/phpc_intersect_ukey_27228_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $want = "1,3 keys=a,c\n";
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($want, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($bin);
        }
    }

    public function testBuiltinCallWiresIntersectUkeyThroughJitArrayUserSetOps(): void
    {
        $builtin = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/array_intersect_ukey.php'
        );
        $this->assertStringContainsString('JitArrayUserSetOps::arrayIntersectUkey', $builtin);
        $this->assertStringNotContainsString('is VM-only', $builtin);
        $runtime = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayUserSetOpsRuntime.php'
        );
        $this->assertStringContainsString('ArrayUserSetOpsKeyLlvm::filterByKey', $runtime);
        $llvm = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/ArrayUserSetOpsKeyLlvm.php'
        );
        $this->assertStringContainsString('JitStringCompare::strcmp', $llvm);
        $this->assertStringNotContainsString('new NestedClosureInvoke', $llvm);
        $this->assertStringNotContainsString('NestedClosureInvokeLlvm', $llvm);
    }
}
