<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: float % int must emit E_DEPRECATED on precision loss (re-#23747, Zend/zend_operators.c mod_function).
 *
 * @group llvm
 * @group aot
 */
final class Issue23747FloatModuloDeprecatedAotTest extends TestCase
{
    public function testAotFloatModuloDeprecatedMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_23747_float_modulo_deprecated_aot.php';
        $bin = sys_get_temp_dir().'/phpc_23747_mod_'.getmypid().'.bin';
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
        $vmJoined = implode("\n", $vmOut);
        $this->assertStringContainsString('Implicit conversion from float 5.5 to int loses precision', $vmJoined);

        try {
            exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aotOut));
            $aotJoined = implode("\n", $aotOut);
            $this->assertStringContainsString('Implicit conversion from float 5.5 to int loses precision', $aotJoined);
            $this->assertSame(
                $this->resultLines($vmOut),
                $this->resultLines($aotOut),
                'stdout result lines must match VM'
            );
        } finally {
            @unlink($bin);
        }
    }

    /** @param list<string> $lines */
    private function resultLines(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn (string $line): bool => !str_contains($line, 'Deprecated:')
        ));
    }
}
