<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for ??= (null coalescing assignment) (#4763, #3792, #1235).
 *
 * php-src: Zend/zend_compile.c (ZEND_ASSIGN_OP / IS_COALESCE), zend_execute.c
 *
 * Skipped when script/jit-runtime-probe.php fails (#98).
 *
 * @group llvm
 */
final class CoalesceAssignJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — ??= JIT execute needs LLVM (#4763)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4763)');
        }
    }

    public function testCoalesceAssignJitPhptExecutesViaMcjit(): void
    {
        $this->assertMcjitOutput(
            $this->phptFixtureCode('coalesce_assign_jit.phpt'),
            "default\nset\nhome\nfrom-get\n"
        );
    }

    public function testCoalesceAssignMixedTypesInOneScript(): void
    {
        $this->assertMcjitOutput(
            <<<'PHP'
<?php
$a = null;
$a ??= 5;
$b = 1;
$b ??= 9;
echo $a, ',', $b, "\n";
PHP,
            "5,1\n"
        );
    }

    /** Issue #29146: dim ??= on undefined CV — bare read stays quiet under bin/jit.php. */
    public function testCoalesceAssignDimUndefVarQuietViaJitCli(): void
    {
        $script = $this->repoRoot.'/test/repro/issue_29146_coalesce_dim_undef_var_quiet.php';
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/jit.php', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, (string) $stderr);
        $this->assertSame("array (\n  'x' => 1,\n)\narray (\n  'k' => 'y',\n)\n", $stdout);
        $this->assertStringNotContainsString('Undefined variable', (string) $stderr);
    }

    /** Issue #29145: var ??= then dim ??= — live CV under bin/jit.php. */
    public function testCoalesceAssignVarThenDimViaJitCli(): void
    {
        $script = $this->repoRoot.'/test/repro/issue_29145_coalesce_var_then_dim.php';
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/jit.php', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, (string) $stderr);
        $this->assertSame("array (\n  'x' => 1,\n)\narray (\n  'x' => 0,\n  'y' => 2,\n)\n", $stdout);
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jit($block, $code, 'test.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame($expected, ob_get_clean());
    }

    private function phptFixtureCode(string $file): string
    {
        $path = $this->repoRoot.'/test/compliance/cases/language/'.$file;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--(?:ENV|EXPECT)/s', $contents, $matches)) {
            $this->fail($file.' FILE section missing');
        }

        return $matches[1];
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
