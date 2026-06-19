<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Class constants with `new` compile and JIT on PHP 8.3+ target (#9850, #3196).
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

    public function testClassConstObjectCompilesOn83Target(): void
    {
        if (!CompilerVersion::supportsClassConstObjectExpressions()) {
            $this->markTestSkipped('class const object expressions require CompilerVersion 8.3+');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public const X = new stdClass();
}
PHP;
        $block = $runtime->parseAndCompile($code, 'class_const_object.php');
        $this->assertNotNull($block);
    }
}
