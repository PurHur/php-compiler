<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for try/catch JIT lowering (#1056, #2084, #2114).
 *
 * IR verify via {@see JIT\Context::compileCommon()} — no MCJIT link/execute.
 * MCJIT execution for EH still segfaults (#2114); {@see Block::requiresVmLowering}
 * keeps {@see bin/jit.php} on the VM path.
 *
 * @group llvm
 */
final class TryCatchJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — try/catch JIT compile test needs LLVM (#2114)');
        }
    }

    public function testTryCatchModulesVerify(): void
    {
        $runtime = new Runtime();
        $cases = [
            'try_catch_get_message.php' => <<<'PHP'
<?php
try {
    throw new Exception('msg');
} catch (Exception $e) {
    echo $e->getMessage();
}
PHP,
            'try_catch_jit_smoke.php' => <<<'PHP'
<?php
class Ex {}
try {
    throw new Ex();
} catch (Ex $e) {
    echo "caught\n";
}
PHP,
            // Issue #3012: dispatch BB must not leak across queued CFG functions.
            'try_catch_cross_func.php' => <<<'PHP'
<?php
function note_progress(): void
{
    try {
        echo "ok\n";
    } catch (\Throwable $e) {
    }
}
function dispatch(): void
{
    throw new \RuntimeException('uncaught in second function');
}
PHP,
        ];

        foreach ($cases as $filename => $code) {
            $block = $runtime->parseAndCompile($code, $filename);
            $runtime->jitCompileBlock($block);
        }

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }

    public function testRequiresVmLoweringForTryCatchWithoutYield(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
try {
    echo 1;
} catch (Exception $e) {
    echo 0;
}
PHP
            ,
            'try_probe.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsExceptionHandlingOpcodes($block));
        $this->assertFalse(Block::containsGeneratorOpcodes($block));
        $this->assertFalse(Block::containsFinallyOpcodes($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }
}
