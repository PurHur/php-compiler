<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Class constants with `new` are rejected at compile time (#9974, Zend/zend_compile.c).
 *
 * @group llvm
 */
final class ClassConstObjectJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — class const object JIT compile test needs LLVM');
        }
    }

    public function testClassConstObjectCompileErrors(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public const X = new stdClass();
}
PHP, 'class_const_object.php');
    }
}
