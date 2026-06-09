<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for array_column() enum-case TypeError guards (#5974).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_column)
 *
 * @group llvm
 */
final class ArrayColumnEnumJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — array_column enum JIT compile test needs LLVM');
        }
    }

    public function testArrayColumnEnumCaseTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'n'; }
array_column([['n' => 1]], E::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_column_enum_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'array_column(): Argument #2 ($column_key) must be of type string|int|null',
            $bc
        );
        $this->assertStringContainsString('__compiler_jit_raise_type_error', $bc);
    }
}
