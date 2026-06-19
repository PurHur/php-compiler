<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Class constants with `new` execute under JIT on PHP 8.3+ target (#9850, #3196).
 *
 * @group llvm
 */
final class ClassConstObjectJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — class const object JIT execute needs LLVM');
        }
    }

    public function testClassConstObjectJitExecuteOn83Target(): void
    {
        if (!CompilerVersion::supportsClassConstObjectExpressions()) {
            $this->markTestSkipped('class const object expressions require CompilerVersion 8.3+');
        }
        $code = file_get_contents($this->repoRoot.'/test/compliance/cases/language/class_const_new_object_run.php');
        $this->assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'class_const_new_object_run.php');
        $this->assertNotNull($block);
        ob_start();
        try {
            $runtime->execute($block);
        } finally {
            $out = ob_get_clean();
        }
        $this->assertStringContainsString('1', $out);
    }
}
