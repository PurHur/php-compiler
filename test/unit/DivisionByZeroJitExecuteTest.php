<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute: / and % with zero divisor throw catchable DivisionByZeroError (#5006).
 *
 * @group llvm
 */
final class DivisionByZeroJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — division-by-zero JIT execute needs LLVM (#5006)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#5006)');
        }
    }

    public function testModuloByZeroCatchableViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
try {
    $x = 5 % 0;
} catch (DivisionByZeroError $e) {
    echo "mod\n";
}
PHP
            , "mod\n");
    }

    public function testDivisionByZeroCatchableViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
try {
    $x = 5 / 0;
} catch (DivisionByZeroError $e) {
    echo "div\n";
}
PHP
            , "div\n");
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'division_by_zero_jit.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->jit($block);
        $out = ob_get_clean();
        $this->assertSame($expected, $out);
    }

    private function jitRuntimeProbeGreen(): bool
    {
        $probe = $this->repoRoot.'/script/jit-runtime-probe.php';
        if (!is_file($probe)) {
            return false;
        }
        $cmd = 'php '.escapeshellarg($probe).' 2>/dev/null';
        exec($cmd, $lines, $code);

        return 0 === $code && in_array('ok', $lines, true);
    }
}
