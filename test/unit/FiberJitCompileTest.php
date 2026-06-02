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
        $this->assertTrue(Block::containsFiberSuspendOpcodes($block));
        $this->assertFalse(Block::containsFiberSuspendOpcodesInScriptScope($block));
        // Full-tree fallback until MCJIT execute green (#4097); then switch requiresVmLowering to script-scope.
        $this->assertTrue(Block::requiresVmLowering($block));
    }

    public function testFiberStartScriptVerifies(): void
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
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
