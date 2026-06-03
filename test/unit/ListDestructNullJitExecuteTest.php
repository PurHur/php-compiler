<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for guarded list destructuring from non-array RHS (#4325, #4531).
 *
 * @group llvm
 */
final class ListDestructNullJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — list destruct null JIT execute needs LLVM (#4531)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4531)');
        }
    }

    public function testNullFalseAndZeroRhsLeavesTargetsUnset(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
[$a, $b] = null;
echo "a=", var_export($a, true), " b=", var_export($b, true), "\n";
list($x) = false;
echo "x=", var_export($x, true), "\n";
[$y] = 0;
echo "y=", var_export($y, true), "\n";
PHP
            ,
            "a=NULL b=NULL\nx=NULL\ny=NULL\n");
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'list_destructure_null.php');
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
