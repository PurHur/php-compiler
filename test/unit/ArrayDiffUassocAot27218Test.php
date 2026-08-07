<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for array_diff_uassoc()/array_intersect_uassoc() (+ peers) (#27218).
 *
 * @group llvm
 * @group aot
 */
final class ArrayDiffUassocAot27218Test extends TestCase
{
    public function testAotArrayDiffUassocClosureMatchesZend(): void
    {
        $this->assertAotReproMatches(
            'aot_array_diff_uassoc_27218.php',
            "diff_uassoc=2,3 keys=b,c\nintersect_uassoc=1 keys=a\n"
        );
    }

    public function testAotArrayUdiffAssocPeersMatchZend(): void
    {
        $this->assertAotReproMatches(
            'aot_array_udiff_assoc_27218.php',
            "udiff_assoc=2,3 keys=b,c\nuintersect_assoc=1 keys=a\n"
        );
    }

    private function assertAotReproMatches(string $reproBasename, string $want): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproBasename;
        $bin = sys_get_temp_dir().'/phpc_'.md5($reproBasename).'_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        $compileOut = [];
        $compileRc = 1;
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            // Heap corruption under thin AOT is intermittent (#23842); require 10 matching runs.
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                $runRc = 1;
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
        foreach ([
            'array_diff_uassoc',
            'array_intersect_uassoc',
            'array_udiff_assoc',
            'array_uintersect_assoc',
        ] as $name) {
            $builtin = (string) file_get_contents(
                dirname(__DIR__, 2).'/ext/standard/'.$name.'.php'
            );
            $this->assertStringNotContainsString('is VM-only', $builtin, $name);
            $this->assertStringContainsString('JitArrayUserSetOps::', $builtin, $name);
        }
        $runtime = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayUserSetOpsRuntime.php'
        );
        $this->assertStringContainsString('diffByAssocPair', $runtime);
        $this->assertStringContainsString('ArrayUserSetOpsUassocLlvm::filterByKeyValue', $runtime);
        $llvm = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/ArrayUserSetOpsUassocLlvm.php'
        );
        $this->assertStringContainsString('compareValueBoxesPublic', $llvm);
        $this->assertStringNotContainsString('new NestedClosureInvoke', $llvm);
    }
}
