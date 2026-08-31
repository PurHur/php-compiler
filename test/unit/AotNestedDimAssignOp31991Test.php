<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: nested dim assign-op with undefined keys must not SIGSEGV (#31991 leftover).
 *
 * @see php-src Zend/zend_execute.c ZEND_FETCH_DIM_W / ZEND_ASSIGN_DIM_OP
 *
 * @group llvm
 * @group aot
 */
final class AotNestedDimAssignOp31991Test extends TestCase
{
    private const EXPECT = "nest=1\nidx=1\n";

    public function testVmNestedDimAssignOpUndefKeys(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_assign_op_undef_key.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_assign_op_undef_key.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('nest=1', $out);
        $this->assertStringContainsString('plus=1', $out);
    }

    public function testAotNestedDimAssignOpUndefKeys(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/differential/cases/i31991_nested_dim_assign_op_undef.php';
        $bin = sys_get_temp_dir().'/phpc_aot_nested_dim_31991_'.getmypid().'.bin';
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
            $stdout = array_values(array_filter(
                $runOut,
                static fn (string $line): bool => !str_starts_with($line, 'PHP Warning:')
                    && !str_starts_with($line, 'PHP Notice:')
                    && !str_starts_with($line, 'PHP Deprecated:')
            ));
            $this->assertSame(self::EXPECT, implode("\n", $stdout)."\n");
            $warnings = array_filter(
                $runOut,
                static fn (string $line): bool => str_contains($line, 'Undefined array key')
            );
            $this->assertCount(4, $warnings, implode("\n", $runOut));
            $combined = implode("\n", $runOut);
            $this->assertStringContainsString('Undefined array key "x"', $combined);
            $this->assertStringContainsString('Undefined array key "y"', $combined);
            $this->assertStringContainsString('Undefined array key 0', $combined);
            $this->assertStringContainsString('Undefined array key 1', $combined);
            $this->assertStringContainsString('i31991_nested_dim_assign_op_undef.php on line 5', $combined);
            $this->assertStringContainsString('i31991_nested_dim_assign_op_undef.php on line 8', $combined);
        } finally {
            @unlink($bin);
        }
    }
}
