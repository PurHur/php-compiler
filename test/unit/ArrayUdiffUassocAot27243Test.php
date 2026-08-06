<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for array_udiff_uassoc()/array_uintersect_uassoc() (#27243).
 *
 * @group llvm
 * @group aot
 */
final class ArrayUdiffUassocAot27243Test extends TestCase
{
    public function testAotArrayUdiffUassocDualClosureMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_array_udiff_uassoc_27243.php';
        $bin = sys_get_temp_dir().'/phpc_udiff_uassoc_27243_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $want = "udiff=2 keys=b\nuintersect=2 keys=b\n";
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

    public function testBuiltinCallWiresUassocThroughJitArrayUserSetOps(): void
    {
        foreach (['array_udiff_uassoc', 'array_uintersect_uassoc'] as $name) {
            $builtin = (string) file_get_contents(
                dirname(__DIR__, 2).'/ext/standard/'.$name.'.php'
            );
            $this->assertStringNotContainsString('is VM-only', $builtin, $name);
            $this->assertStringContainsString('JitArrayUserSetOps::', $builtin, $name);
        }
        $runtime = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayUserSetOpsRuntime.php'
        );
        $this->assertStringContainsString('ArrayUserSetOpsUassocLlvm::filterByKeyValue', $runtime);
        $llvm = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/ArrayUserSetOpsUassocLlvm.php'
        );
        $this->assertStringContainsString('compareValueBoxesPublic', $llvm);
        $this->assertStringNotContainsString('new NestedClosureInvoke', $llvm);
        $this->assertStringNotContainsString('NestedClosureInvokeLlvm', $llvm);
    }
}
