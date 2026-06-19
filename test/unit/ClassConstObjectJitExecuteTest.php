<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Class constants with `new` must compile-error per php-src (#9804).
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

    public function testClassConstObjectCompileErrorsBeforeJit(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents($this->repoRoot.'/test/compliance/cases/language/class_const_object_run.php');
        $this->assertNotFalse($code);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile($code, 'class_const_object_run.php');
    }
}
