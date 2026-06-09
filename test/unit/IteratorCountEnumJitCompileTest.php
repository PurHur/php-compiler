<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for iterator_count() enum-case TypeError guards (#6232).
 *
 * php-src: ext/spl/php_spl.c — PHP_FUNCTION(iterator_count)
 *
 * @group llvm
 */
final class IteratorCountEnumJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — iterator_count enum JIT compile test needs LLVM');
        }
    }

    public function testIteratorCountEnumCaseTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'x'; }
iterator_count(E::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'iterator_count_enum_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'iterator_count(): Argument #1 ($iterator) must be of type Traversable|array',
            $bc
        );
        $this->assertStringContainsString('__compiler_jit_raise_type_error', $bc);
    }
}
