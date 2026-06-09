<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for array_slice() Z_PARAM_LONG lowering (#4176).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_slice)
 *
 * @group llvm
 */
final class ArraySliceNumericStringJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — array_slice numeric-string JIT compile test needs LLVM');
        }
    }

    public function testNumericStringOffsetLengthLowering(): void
    {
        $code = <<<'PHP'
<?php
echo json_encode(array_slice([1, 2, 3], '1', '1')), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_slice_numeric_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $this->addToAssertionCount(1);
    }

    public function testNonNumericStringTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
try {
    array_slice([1], 'abc');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_slice_bad_offset_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'array_slice(): Argument #2 ($offset) must be of type int, string given',
            $bc
        );
    }
}
