<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: undefined variable inside closure must warn and error_get_last() cites inner line (#13390).
 *
 * @group llvm
 * @group aot
 */
final class UndefinedVariableClosureAotTest extends TestCase
{
    public function testAotClosureUndefinedVariableMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_undef_var_closure_error_get_last.php';
        $bin = sys_get_temp_dir().'/phpc_undef_closure_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $vmCmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($vmCmd, $vmOut, $vmRc);
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));

        try {
            exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aotOut));
            $this->assertSame(implode("\n", $vmOut), implode("\n", $aotOut));
        } finally {
            @unlink($bin);
        }
    }
}
