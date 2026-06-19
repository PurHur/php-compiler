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
        $code = <<<'PHP'
<?php
class C {
    public const X = new stdClass();
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile($code, 'class_const_object.php');
    }
}
