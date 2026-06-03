<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for closure use (&$var) (#4625, Zend/zend_closures.c).
 *
 * @group llvm
 */
final class ClosureByRefJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — closure by-ref JIT execute needs LLVM (#4625)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4625)');
        }
    }

    public function testClosureByRefMutateOuterScope(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
declare(strict_types=1);

$n = 0;
$inc = function () use (&$n): void { $n++; };
$inc();
$inc();
echo $n, "\n";
PHP
            ,
            "2\n");
    }

    public function testClosureByRefReadsLiveBinding(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
$n = 10;
$f = function ($x) use (&$n) {
    return $x + $n;
};
echo $f(5), "\n";
$n = 99;
echo $f(5), "\n";
PHP
            ,
            "15\n104\n");
    }

    public function testClosureByRefAssignMutatesEnclosing(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
$x = 1;
$f = function () use (&$x) {
    $x = 2;
};
$f();
echo $x, "\n";
PHP
            ,
            "2\n");
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsClosureByRefCaptureOpcodes($block));
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
