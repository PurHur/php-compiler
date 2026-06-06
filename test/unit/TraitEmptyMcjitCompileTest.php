<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT compile-only for DECLARE_TRAIT units defer to VM lowering (#6284, #3609).
 *
 * @group llvm
 */
final class TraitEmptyMcjitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — trait MCJIT compile test needs LLVM (#6284)');
        }
    }

    public function testEmptyTraitDefersMcjitUntilStable(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
trait T {}
echo "ok\n";
PHP
            ,
            'trait_empty_mcjit_compile.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsDeclareTraitOpcodesInScriptScope($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }

    public function testEmptyTraitMcjitCompileOnlyViaBinJit(): void
    {
        $script = $this->repoRoot.'/test/repro/trait_empty_mcjit_probe.php';
        $this->assertFileExists($script);
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        $this->assertNotFalse($jit);
        $env = $_ENV;
        $env['PHP_COMPILER_SKIP_LLVM_PRELOAD'] = '1';
        $proc = proc_open(
            array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $jit, '-l', $script]),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, 'MCJIT compile-only must not segfault: '.$stderr);
    }

    public function testEmptyTraitExecutesViaVmFallback(): void
    {
        $varDir = $this->repoRoot.'/var';
        if (!is_dir($varDir) && !mkdir($varDir, 0775, true) && !is_dir($varDir)) {
            $this->fail('Could not create var/ for trait MCJIT execute script');
        }
        $script = $varDir.'/trait_empty_mcjit_run_'.getmypid().'.php';
        file_put_contents($script, <<<'PHP'
<?php
trait T {}
echo "ok\n";
PHP
        );
        try {
            $jit = realpath($this->repoRoot.'/bin/jit.php');
            $this->assertNotFalse($jit);
            $env = $_ENV;
            $env['PHP_COMPILER_SKIP_LLVM_PRELOAD'] = '1';
            $proc = proc_open(
                array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $jit, $script]),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->repoRoot,
                $env
            );
            $this->assertIsResource($proc);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            $this->assertSame(0, $exit, trim($stderr));
            $this->assertSame("ok\n", $stdout);
        } finally {
            @unlink($script);
        }
    }
}
