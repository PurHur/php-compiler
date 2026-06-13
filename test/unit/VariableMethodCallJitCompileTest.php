<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for instance calls via assigned variables (#8407).
 *
 * @group llvm
 */
final class VariableMethodCallJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — variable method-call JIT compile test needs LLVM');
        }
    }

    public function testAssignedVariableReceiverAndArgumentCompile(): void
    {
        $target = $this->repoRoot.'/test/fixtures/aot/compile-only/variable_method_call_jit.php';
        $this->assertFileExists($target);
        $code = (string) file_get_contents($target);
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile($code, 'variable_method_call_jit.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $this->addToAssertionCount(1);
    }
}
