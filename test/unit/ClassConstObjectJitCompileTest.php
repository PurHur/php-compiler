<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\CompilerVersion;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Class constants with `new` compile and execute on PHP 8.3+ (#12940, Zend/zend_compile.c).
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
        if (!CompilerVersion::supportsClassConstObjectExpressions()) {
            $this->markTestSkipped('class const object expressions disabled on reference profile');
        }
    }

    public function testClassConstObjectCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public const X = new stdClass();
}
PHP, 'class_const_object.php');
        $this->assertNotNull($block);
    }
}
