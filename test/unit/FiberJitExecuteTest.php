<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for Fiber start/resume (#4097, Zend/zend_fibers.c).
 *
 * Uses Runtime::jit() directly; bin/jit.php skips VM fallback when suspend is only in closures.
 *
 * @group llvm
 */
final class FiberJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — fiber JIT execute needs LLVM (#4097)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4097)');
        }
    }

    public function testFiberSuspendStartExecutesViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
$fiber = new Fiber(function (): void {
    Fiber::suspend('ok');
});
echo $fiber->start(), "\n";
PHP
            ,
            "ok\n");
    }

    public function testFiberResumeWithEchoExecutesViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
$fiber = new Fiber(function (): void {
    echo Fiber::suspend('first');
    echo "done\n";
});
echo $fiber->start(), "\n";
$fiber->resume();
PHP
            ,
            "first\ndone\n");
    }

    public function testRequiresVmLoweringSkipsNestedFiberCallback(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
$fiber = new Fiber(function (): void {
    Fiber::suspend('ok');
});
echo $fiber->start(), "\n";
PHP
            ,
            'fiber_scope_probe.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $this->assertFalse(Block::containsFiberSuspendOpcodesInScriptScope($block));
        $this->assertTrue(Block::containsFiberSuspendOpcodes($block));
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        $this->assertNotNull($block);
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

        return 0 === $code && str_contains(implode("\n", $out), 'jit-runtime-probe OK');
    }
}
