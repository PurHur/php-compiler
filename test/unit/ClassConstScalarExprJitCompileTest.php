<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for class constant scalar expressions (#5394, #3567).
 *
 * @group llvm
 */
final class ClassConstScalarExprJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — class const scalar expr JIT compile test needs LLVM (#5394)');
        }
    }

    public function testClassConstScalarExprModuleVerify(): void
    {
        $runtime = new Runtime();
        $path = $this->repoRoot.'/test/compliance/cases/language/class_const_scalar_expr_run.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $block = $runtime->parseAndCompile($code, $path);
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
