<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for Fiber MCJIT lowering (#4097, #4019).
 *
 * @group llvm
 */
final class FiberJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — fiber JIT compile test needs LLVM (#4097)');
        }
    }

    public function testFiberSuspendOnlyInClosureBody(): void
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
            'fiber_jit_compile.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsFiberSuspendOpcodesInScriptScope($block));
        $this->assertTrue(Block::containsFiberSuspendOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }

    public function testFiberStartScriptVerifies(): void
    {
        $varDir = $this->repoRoot.'/var';
        if (!is_dir($varDir) && !mkdir($varDir, 0775, true) && !is_dir($varDir)) {
            $this->fail('Could not create var/ for fiber JIT lint script');
        }
        $script = $varDir.'/fiber_jit_compile_verify.php';
        file_put_contents($script, <<<'PHP'
<?php
$fiber = new Fiber(function (): void {
    Fiber::suspend('ok');
});
echo $fiber->start(), "\n";
PHP
        );
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        $this->assertNotFalse($jit);
        $env = $_ENV;
        foreach ($_SERVER as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $cmd = array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $jit, '-l', $script]);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, 'fiber JIT lint: '.trim((string) $stderr));
    }

    public function testFiberThrowProxyRegistered(): void
    {
        // Proxy classes are registered in JIT\Context::defineBuiltins(); avoid in-process
        // loadJitContext() here — LLVM init segfaults after bin/jit.php subprocess (#98).
        $this->assertTrue(is_subclass_of(\PHPCompiler\JIT\Call\FiberThrow::class, \PHPCompiler\JIT\Call::class));
        $this->assertTrue(is_subclass_of(\PHPCompiler\JIT\Call\FiberGetReturn::class, \PHPCompiler\JIT\Call::class));
        $this->assertTrue(is_subclass_of(\PHPCompiler\JIT\Call\FiberStart::class, \PHPCompiler\JIT\Call::class));
        $this->assertTrue(is_subclass_of(\PHPCompiler\JIT\Call\FiberConstruct::class, \PHPCompiler\JIT\Call::class));
    }
}
