<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: function-static locals must not emit spurious Undefined variable warnings.
 *
 * @group llvm
 * @group aot
 */
final class FunctionStaticUndefWarnAotTest extends TestCase
{
    public function testAotFunctionStaticArrayOffsetInitMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesVm(
            dirname(__DIR__, 2).'/test/repro/maintainer_gap_static_array_offset_init.php'
        );
    }

    public function testAotFunctionStaticLiteralDefaultMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_fn_static_literal_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
function f(): void {
    static $x = 5;
    var_export($x === 5);
}
f();
echo "\n";
PHP
        );
        try {
            $this->assertAotMatchesVm($src);
        } finally {
            @unlink($src);
        }
    }

    private function assertAotMatchesVm(string $src): void
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_fn_static_undef_'.getmypid().'_'.md5($src).'.bin';
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
