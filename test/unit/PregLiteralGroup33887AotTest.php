<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: preg_match single / named literal capture groups (#33887).
 *
 * @group llvm
 * @group aot
 */
final class PregLiteralGroup33887AotTest extends TestCase
{
    public function testAotSingleAndNamedLiteralGroupsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_preg_literal_group_33887.php';
        $bin = sys_get_temp_dir().'/phpc_preg_lit_33887_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $got = implode("\n", $runOut)."\n";
            $this->assertStringContainsString('unnamed_r=1 m0=x m1=x', $got);
            $this->assertStringContainsString('named_r=1 a=x m1=x', $got);
            $this->assertStringContainsString('p_r=1 b=foo m1=foo', $got);
        } finally {
            @unlink($bin);
        }
    }
}
