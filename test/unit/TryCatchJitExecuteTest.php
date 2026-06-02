<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for simple try/catch (no finally) after #2114 catch double-compile fix.
 *
 * Uses Runtime::jit() directly (bin/jit.php JIT-lowers try/catch/finally in functions, #4246).
 * Skipped when script/jit-runtime-probe.php fails.
 *
 * @group llvm
 */
final class TryCatchJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — try/catch JIT execute needs LLVM (#2114)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#2114)');
        }
    }

    public function testSimpleTryCatchExecutesViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
class Ex {}
try {
    throw new Ex();
} catch (Ex $e) {
    echo "caught\n";
}
PHP
            , "caught\n");
    }

    public function testTryWithoutThrowExecutesViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
try {
    echo "ok\n";
} catch (Exception $e) {
    echo "catch\n";
}
PHP
            , "ok\n");
    }

    public function testContainsFinallyOpcodesDetectsFinally(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
try {
    echo 1;
} finally {
}
PHP
            ,
            'finally_probe.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsFinallyOpcodes($block));
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsFinallyOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jit($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame($expected, ob_get_clean());
    }

    private function jitRuntimeProbeGreen(): bool
    {
        $probe = $this->repoRoot.'/script/jit-runtime-probe.php';
        $cmd = sprintf(
            'bash -lc %s',
            escapeshellarg('source '.escapeshellarg($this->repoRoot.'/script/php-env.sh')
                .' && '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($probe))
        );
        exec($cmd, $out, $code);

        return 0 === $code;
    }
}
